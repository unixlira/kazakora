<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Financeiro\Models\MercadoPagoPagamento;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Services\InvoiceService;
use App\Notifications\FiscalAlertNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Pedido cancelado no canal com NF-e de venda autorizada há menos de 24h:
 * cancela a nota na SEFAZ, sem multa (Lira, 2026-10-26: "sem multa").
 *
 * O botão Cancelar pedido do admin já cancelava a nota
 * (CancelamentoDoPedido), mas o cancelamento que chega da Shopee, do ML,
 * do TikTok ou da Amazon só mudava o status do pedido: a nota ficava viva e
 * passava do prazo (95 casos em 26/10).
 *
 * Nunca cancela fora do prazo: só nota autorizada há menos de
 * 24h − nfe.cancelamento_auto_folga_minutos, e sempre sem confirmar multa
 * (InvoiceService::cancelInvoice recusa a janela extemporânea). Passou
 * disso, a devolução sai pelo devolucoes:vendas-canceladas --automatico.
 */
class CancelarNotasDePedidosCancelados extends Command
{
    protected $signature = 'nfe:cancelar-pedidos-cancelados
        {--simular : Só lista, não cancela nada}';

    protected $description = 'Cancela na SEFAZ (sem multa, dentro das 24h) a NF-e de venda de pedido cancelado no canal';

    /** Depois de uma falha, espera 1h antes de tentar a mesma nota de novo. */
    private const ESPERA_FALHA_MINUTOS = 60;

    public function handle(InvoiceService $invoices): int
    {
        $simular = (bool) $this->option('simular');

        if (! $simular && ! self::servidorDeProducao()) {
            $this->warn('Fora do servidor de produção (APP_URL não é '.config('nfe.automatico_host').'): nada é cancelado. Use --simular.');

            return self::SUCCESS;
        }

        $canceladas = [];
        $falhas = [];

        foreach ($this->casos() as $nota) {
            $pedido = $nota->order;
            $linha = sprintf('#%d %s %s: NF-e %d-%d R$ %s, autorizada %s', $pedido->id, $pedido->origin, $pedido->external_order_id, $nota->serie, $nota->numero,
                number_format((float) $nota->valor_total, 2, ',', '.'), $nota->autorizada_em->format('d/m H:i'));

            if ($simular) {
                $this->line($linha);

                continue;
            }

            $chaveEspera = "nfe-cancelar-pedido-cancelado:{$nota->id}";
            if (Cache::has($chaveEspera)) {
                continue;
            }

            try {
                $invoices->cancelInvoice($nota, "Pedido cancelado no canal ({$pedido->origin} {$pedido->external_order_id})");
                $this->info("{$linha} -> CANCELADA");
                $canceladas[] = "#{$pedido->id}";
            } catch (Throwable $e) {
                Cache::put($chaveEspera, true, now()->addMinutes(self::ESPERA_FALHA_MINUTOS));
                Log::warning('nfe:cancelar-pedidos-cancelados falhou', ['order_id' => $pedido->id, 'invoice_id' => $nota->id, 'erro' => $e->getMessage()]);
                $this->error("{$linha} -> não cancelada: ".mb_substr($e->getMessage(), 0, 200));
                $falhas[] = "#{$pedido->id}";
            }
        }

        if ($canceladas !== [] || $falhas !== []) {
            $this->avisar($canceladas, $falhas);
        }

        return self::SUCCESS;
    }

    /** Só o servidor de produção (APP_URL kazakora.com) age sozinho; ver config nfe.automatico_host. */
    public static function servidorDeProducao(): bool
    {
        return strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)) === strtolower((string) config('nfe.automatico_host'));
    }

    /** @return Collection<int, Invoice> */
    public function casos(): Collection
    {
        $limite = now()->subMinutes((int) config('nfe.cancelamento_horas', 24) * 60 - (int) config('nfe.cancelamento_auto_folga_minutos', 30));
        $valeram = collect(config('nfe.vendas_que_valeram', []))->map(fn ($id) => (int) $id)->all();
        $cobertosPeloMl = MercadoPagoPagamento::query()->where('status_detalhe', 'bpp_covered')->whereNotNull('order_id')->pluck('order_id')->all();

        return Invoice::query()
            ->where('status', Invoice::STATUS_AUTHORIZED)
            ->whereNotNull('chave_acesso')
            ->whereNotNull('protocolo_autorizacao')
            ->where('autorizada_em', '>', $limite)
            ->whereHas('order', fn ($q) => $q
                ->where('status', Order::STATUS_CANCELLED)
                ->whereNotIn('origin', [Order::ORIGIN_SALES_RETURN_INVOICE, Order::ORIGIN_PURCHASE_RETURN_INVOICE, Order::ORIGIN_MANUAL_INVOICE])
                ->whereNotIn('id', array_merge($valeram, $cobertosPeloMl)))
            ->with('order')
            ->orderBy('autorizada_em')
            ->get();
    }

    /**
     * @param  list<string>  $canceladas
     * @param  list<string>  $falhas
     */
    private function avisar(array $canceladas, array $falhas): void
    {
        $texto = count($canceladas).' NF-e de pedido cancelado no canal cancelada(s) na SEFAZ, sem multa: '.(implode(', ', $canceladas) ?: 'nenhuma').'.';
        if ($falhas !== []) {
            $texto .= ' Não cancelada(s), tenta de novo em 1h (ver log): '.implode(', ', $falhas).'.';
        }

        $admins = User::query()->where('role', User::ROLE_ADMIN)->get();
        if ($admins->isNotEmpty()) {
            rescue(fn () => Notification::send($admins, new FiscalAlertNotification($texto)), report: false);
        }
    }
}
