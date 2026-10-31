<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Admin\Http\Controllers\DevolucoesController;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Services\DevolucaoFiscalAutomatica;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Models\MarketplaceReturn;
use App\Notifications\DevolucaoPacNotification;
use App\Services\Correios\CorreiosRastroService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Etiqueta de Devolução PAC acompanhada pelo rastreio (Lira 2026-10-31:
 * "assim que receber o PAC já gera nota de devolução na hora"). Os Correios
 * não mandam webhook para o nosso contrato, então é consulta a cada 10 minutos.
 *
 * Postada: o caso vai para "Produto a caminho". Entregue: o caso vai para
 * "Entregue", o saved() de MarketplaceReturn manda a NF-e de entrada à SEFAZ e
 * o sino avisa. Quando a nota responde, o sino avisa de novo com o resultado.
 * Caso da API segue a plataforma: aqui só o aviso, a situação não muda.
 */
class AcompanharDevolucaoPac extends Command
{
    protected $signature = 'devolucoes:acompanhar-pac {--simular : só mostra, não grava nem avisa}';

    protected $description = 'Acompanha o rastreio das etiquetas de Devolução PAC e avisa no sino a chegada e o resultado da nota';

    private const AVISOU_CHEGADA = 'pac_chegou';

    private const AVISOU_NOTA = 'pac_nota';

    /** Sem resposta da nota depois disso, o sino avisa em vez de esperar calado. */
    private const NOTA_ATE_HORAS = 2;

    public function handle(CorreiosRastroService $rastro): int
    {
        $simular = (bool) $this->option('simular');

        CorreiosPrePostagem::query()
            ->where('devolucao', true)
            ->where('status', CorreiosPrePostagem::STATUS_GERADA)
            ->whereNotNull('codigo_objeto')
            ->whereNotNull('order_id')
            ->where('created_at', '>=', now()->subDays(90))
            ->chunkById(50, function ($lote) use ($rastro, $simular) {
                foreach ($lote as $etiqueta) {
                    $caso = $this->casoDa($etiqueta);
                    $avisados = $caso?->alerts_notified ?? [];

                    if (! $caso || in_array(self::AVISOU_NOTA, $avisados, true)) {
                        continue;
                    }

                    if (in_array(self::AVISOU_CHEGADA, $avisados, true)) {
                        $simular || $this->avisarNota($caso, $etiqueta);

                        continue;
                    }

                    try {
                        $r = $rastro->consultar($etiqueta->codigo_objeto);
                    } catch (Throwable $e) {
                        Log::warning('devolucao_pac.rastreio_falhou', ['pre_postagem_id' => $etiqueta->id, 'error' => $e->getMessage()]);

                        continue;
                    }

                    $this->line("#{$etiqueta->id} {$etiqueta->codigo_objeto} caso {$caso->id}: ".($r['ultimo'] ?? 'sem evento'));

                    if ($simular) {
                        continue;
                    }

                    if ($r['entregue']) {
                        $this->chegou($caso, $etiqueta, $r['entregue_em']);
                    } elseif ($r['postado'] && $caso->manual && $caso->situacao === MarketplaceReturn::AGUARDANDO_ENVIO) {
                        $caso->update(['situacao' => MarketplaceReturn::EM_TRANSITO]);
                        $this->evento($caso, "Correios: Devolução PAC {$etiqueta->codigo_objeto} postada.");
                        DevolucoesController::limparContagem();
                    }
                }
            });

        return self::SUCCESS;
    }

    /** O caso da etiqueta; a etiqueta antiga do mesmo pedido cai no caso em aberto dele. */
    private function casoDa(CorreiosPrePostagem $etiqueta): ?MarketplaceReturn
    {
        $doPedido = MarketplaceReturn::query()
            ->where('order_id', $etiqueta->order_id)
            ->where('kind', MarketplaceReturn::KIND_DEVOLUCAO);

        return (clone $doPedido)
            ->where('manual', true)
            ->where('tracking_number', $etiqueta->codigo_objeto)
            ->where('situacao', '!=', MarketplaceReturn::CANCELADA)
            ->latest('id')
            ->first()
            ?? $doPedido
                ->whereIn('situacao', MarketplaceReturn::EM_ABERTO)
                ->orderByDesc('manual')
                ->orderByDesc('id')
                ->first();
    }

    private function chegou(MarketplaceReturn $caso, CorreiosPrePostagem $etiqueta, ?Carbon $entregueEm): void
    {
        $quando = $entregueEm?->copy()->timezone('America/Sao_Paulo')->format('d/m/Y H:i');

        $this->marcar($caso, self::AVISOU_CHEGADA);
        $this->evento($caso, "Correios: Devolução PAC {$etiqueta->codigo_objeto} entregue".($quando ? " em {$quando}" : '').'.');

        $mudou = $caso->manual && in_array($caso->situacao, [MarketplaceReturn::AGUARDANDO_ENVIO, MarketplaceReturn::EM_TRANSITO], true);

        if ($mudou) {
            $caso->update(['situacao' => MarketplaceReturn::ENTREGUE, 'delivered_at' => $entregueEm ?? now()]);
            DevolucoesController::limparContagem();
        }

        $this->avisar(
            $caso,
            "Devolução PAC chegou: pedido #{$caso->order_id}",
            "{$etiqueta->customer_name}, rastreio {$etiqueta->codigo_objeto}".($quando ? ", entregue em {$quando}" : '').'. '
                .($caso->manual ? 'A nota de devolução foi para a SEFAZ e o resultado vem no próximo aviso.' : 'O caso segue a plataforma: a nota sai quando o canal marcar a entrega.'),
            'info',
        );
    }

    private function avisarNota(MarketplaceReturn $caso, CorreiosPrePostagem $etiqueta): void
    {
        $caso->load('devolucaoOrder.invoice');
        $nota = $caso->devolucaoOrder?->invoice;
        $pedido = "pedido #{$caso->order_id}";

        [$titulo, $mensagem, $nivel] = match (true) {
            $nota?->status === Invoice::STATUS_AUTHORIZED => ["Nota de devolução {$nota->serie}-{$nota->numero} autorizada: {$pedido}", 'Devolução PAC '.$etiqueta->codigo_objeto.': a SEFAZ autorizou a nota. Falta conferir o produto.', 'sucesso'],
            in_array($nota?->status, [Invoice::STATUS_REJECTED, Invoice::STATUS_DENIED, Invoice::STATUS_ERROR, Invoice::STATUS_CANCELLED], true) => ["Nota de devolução com problema: {$pedido}", 'A nota ficou como "'.$nota->status.'". '.($nota->motivo_rejeicao ?: 'Veja o motivo na nota.'), 'erro'],
            in_array($caso->devolucao_fiscal_status, [DevolucaoFiscalAutomatica::PELO_CANAL, DevolucaoFiscalAutomatica::SEM_NOTA, DevolucaoFiscalAutomatica::PENDENCIA, DevolucaoFiscalAutomatica::DESCARTADA], true) => ["Nota de devolução não saiu: {$pedido}", $caso->devolucao_fiscal_obs ?: 'Veja o caso em Devoluções.', 'erro'],
            $this->chegadaHaMaisDe($caso, self::NOTA_ATE_HORAS) => ["Nota de devolução sem resposta: {$pedido}", 'Passaram '.self::NOTA_ATE_HORAS.' horas da chegada e a nota não respondeu. Veja o caso em Devoluções.', 'erro'],
            default => [null, null, null],
        };

        if (! $titulo) {
            return;
        }

        $this->marcar($caso, self::AVISOU_NOTA);
        $this->avisar($caso, $titulo, $mensagem, $nivel);
    }

    private function chegadaHaMaisDe(MarketplaceReturn $caso, int $horas): bool
    {
        $chegada = $caso->events()->where('description', 'like', 'Correios: Devolução PAC % entregue%')->max('happened_at');

        return ! $chegada || now()->parse($chegada)->lte(now()->subHours($horas));
    }

    private function marcar(MarketplaceReturn $caso, string $chave): void
    {
        $caso->forceFill(['alerts_notified' => array_values(array_unique([...($caso->alerts_notified ?? []), $chave]))])->saveQuietly();
    }

    private function evento(MarketplaceReturn $caso, string $texto): void
    {
        $caso->events()->create(['situacao' => $caso->situacao, 'description' => $texto, 'happened_at' => now()]);
    }

    private function avisar(MarketplaceReturn $caso, string $titulo, string $mensagem, string $nivel): void
    {
        rescue(fn () => Notification::send(
            User::query()->where('role', User::ROLE_ADMIN)->get(),
            new DevolucaoPacNotification($titulo, $mensagem, "/admin/devolucoes?caso={$caso->id}", $nivel),
        ), report: true);
    }
}
