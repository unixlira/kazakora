<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Fiscal\Support\CancelamentoAutomaticoDaNota;
use App\Notifications\FiscalAlertNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/**
 * Pedido cancelado no canal com NF-e de venda autorizada há menos de 24h:
 * cancela a nota na SEFAZ, sem multa (Lira, 2026-10-26: "sem multa").
 *
 * O botão Cancelar pedido do admin já cancelava a nota
 * (CancelamentoDoPedido), mas o cancelamento que chega da Shopee, do ML,
 * do TikTok ou da Amazon só mudava o status do pedido: a nota ficava viva e
 * passava do prazo (95 casos em 26/10).
 *
 * Desde 2026-10-29 o webhook já cancela na hora
 * (CancelarNotaDoPedidoCanceladoJob); esta rodada de 10 min fica de rede e
 * tenta de novo o que deu erro. Nunca cancela fora do prazo: só nota
 * autorizada há menos de 24h − nfe.cancelamento_auto_folga_minutos, e
 * sempre sem confirmar multa (InvoiceService::cancelInvoice recusa a janela
 * extemporânea). Passou disso, a devolução sai pelo
 * devolucoes:vendas-canceladas --automatico.
 */
class CancelarNotasDePedidosCancelados extends Command
{
    protected $signature = 'nfe:cancelar-pedidos-cancelados
        {--simular : Só lista, não cancela nada}
        {--checagem : Rechecagem das 22h e das 9h: tenta de novo as que deram erro, sem a espera de 1h}';

    protected $description = 'Cancela na SEFAZ (sem multa, dentro das 24h) a NF-e de venda de pedido cancelado no canal';

    /** Depois de uma falha, espera 1h antes de tentar a mesma nota de novo. */
    private const ESPERA_FALHA_MINUTOS = 60;

    public function handle(CancelamentoAutomaticoDaNota $cancelamento): int
    {
        $simular = (bool) $this->option('simular');

        if (! $simular && ! CancelamentoAutomaticoDaNota::servidorDeProducao()) {
            $this->warn('Fora do servidor de produção (APP_URL não é '.config('nfe.automatico_host').'): nada é cancelado. Use --simular.');

            return self::SUCCESS;
        }

        $canceladas = [];
        $falhas = [];

        foreach ($cancelamento->casos() as $nota) {
            $pedido = $nota->order;
            $linha = sprintf('#%d %s %s: NF-e %d-%d R$ %s, autorizada %s', $pedido->id, $pedido->origin, $pedido->external_order_id, $nota->serie, $nota->numero,
                number_format((float) $nota->valor_total, 2, ',', '.'), $nota->autorizada_em->format('d/m H:i'));

            if ($simular) {
                $this->line($linha);

                continue;
            }

            $chaveEspera = "nfe-cancelar-pedido-cancelado:{$nota->id}";
            if (! $this->option('checagem') && Cache::has($chaveEspera)) {
                continue;
            }

            if ($cancelamento->cancelar($nota)) {
                $this->info("{$linha} -> CANCELADA");
                $canceladas[] = "#{$pedido->id}";
            } else {
                Cache::put($chaveEspera, true, now()->addMinutes(self::ESPERA_FALHA_MINUTOS));
                $this->error("{$linha} -> não cancelada (alerta em Sistema › Alertas)");
                $falhas[] = "#{$pedido->id}";
            }
        }

        foreach ($cancelamento->semProtocolo() as $nota) {
            $linha = sprintf('#%d %s: NF-e %d-%d sem protocolo de autorização (Bling)', $nota->order->id, $nota->order->origin, $nota->serie, $nota->numero);
            $this->line($linha.($simular ? '' : ' -> para checagem'));

            if (! $simular && ($this->option('checagem') || Cache::add("nfe-sem-protocolo-marcada:{$nota->id}", true, now()->addDay()))) {
                $cancelamento->marcarParaChecagem($nota, 'Nota sem protocolo de autorização gravado (emitida pelo Bling): o sistema não consegue cancelar sozinho. Cancele no Bling.');
            }
        }

        if ($canceladas !== [] || $falhas !== []) {
            $this->avisar($canceladas, $falhas);
        }

        return self::SUCCESS;
    }

    /** Mantido para quem já chamava pelo comando. */
    public static function servidorDeProducao(): bool
    {
        return CancelamentoAutomaticoDaNota::servidorDeProducao();
    }

    /**
     * @param  list<string>  $canceladas
     * @param  list<string>  $falhas
     */
    private function avisar(array $canceladas, array $falhas): void
    {
        $texto = count($canceladas).' NF-e de pedido cancelado no canal cancelada(s) na SEFAZ, sem multa: '.(implode(', ', $canceladas) ?: 'nenhuma').'.';
        if ($falhas !== []) {
            $texto .= ' Não cancelada(s), tenta de novo em 1h (ver Sistema › Alertas): '.implode(', ', $falhas).'.';
        }

        $admins = User::query()->where('role', User::ROLE_ADMIN)->get();
        if ($admins->isNotEmpty()) {
            rescue(fn () => Notification::send($admins, new FiscalAlertNotification($texto)), report: false);
        }
    }
}
