<?php

namespace App\Modules\Fiscal\Services;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Fiscal\Models\Invoice;
use App\Services\Bling\BlingInvoiceImporter;
use Illuminate\Support\Facades\Storage;

/**
 * Registro da nota cancelada no histórico do pedido (pedido do Lira em
 * 2026-11-03, caso #3779: NF-e 3-655 gerada no Bling em dobro com a 6-568
 * que o TikTok emitiu, cancelada em seguida). "Pra gente ter o registro de
 * tudo": chave, número, plataforma que gerou, flag de cancelada e o XML,
 * se houver. Vale para qualquer venda.
 *
 * O registro do pedido (invoices) guarda uma nota só; quando a cancelada é
 * trocada pela nota que vale, é este evento que preserva a cancelada.
 */
class HistoricoDeNotas
{
    public function __construct(private readonly OrderFulfillmentTimeline $timeline) {}

    public function registrarCancelada(Invoice $nota): ?OrderFulfillmentEvent
    {
        $pedido = $nota->order;

        if (! $pedido || ! $nota->chave_acesso) {
            return null;
        }

        $xml = $this->xmlGuardado($nota->xml_path) ?? $this->baixarXmlDoBling($pedido, $nota->plataformaEmissora(), $nota->chave_acesso);

        if ($xml !== null && $xml !== $nota->xml_path) {
            $nota->forceFill(['xml_path' => $xml])->saveQuietly();
        }

        return $this->registrar($pedido, [
            'plataforma' => $nota->plataformaEmissora(),
            'serie' => $nota->serie,
            'numero' => $nota->numero,
            'chave' => $nota->chave_acesso,
            'valor' => $nota->valor_total !== null ? (float) $nota->valor_total : null,
            'autorizada_em' => $nota->autorizada_em?->toIso8601String(),
            'cancelada_em' => ($nota->cancelada_em ?? now())->toIso8601String(),
            'protocolo_cancelamento' => $nota->protocolo_cancelamento,
            'motivo_cancelamento' => $nota->motivo_cancelamento,
            'xml_path' => $xml,
            'xml_cancelamento_path' => $this->xmlGuardado($nota->xml_cancelamento_path),
        ]);
    }

    /**
     * Nota cancelada que não está (ou não está mais) no registro do pedido,
     * ex.: a do Bling depois que o pedido passou a ter a nota do TikTok.
     *
     * @param  array{plataforma: string, serie: ?int, numero: ?int, chave: string, valor?: ?float, autorizada_em?: ?string, cancelada_em?: ?string, protocolo_cancelamento?: ?string, motivo_cancelamento?: ?string, xml_path?: ?string, xml_cancelamento_path?: ?string}  $dados
     */
    public function registrar(Order $pedido, array $dados): ?OrderFulfillmentEvent
    {
        $chave = preg_replace('/\D/', '', (string) $dados['chave']);

        if (strlen($chave) !== 44) {
            return null;
        }

        $jaRegistrado = OrderFulfillmentEvent::query()
            ->where('order_id', $pedido->id)
            ->where('step', OrderFulfillmentEvent::STEP_INVOICE_CANCELLED)
            ->where('context->chave', $chave)
            ->first();

        if ($jaRegistrado) {
            return $jaRegistrado;
        }

        $dados['xml_path'] ??= $this->baixarXmlDoBling($pedido, $dados['plataforma'], $chave);

        $numero = ($dados['serie'] ?? '?').'-'.($dados['numero'] ?? '?');
        $mensagem = "NF-e {$numero} gerada na plataforma {$dados['plataforma']} e cancelada em seguida (chave {$chave}"
            .(! empty($dados['protocolo_cancelamento']) ? ", protocolo de cancelamento {$dados['protocolo_cancelamento']}" : '')
            .'). '.(! empty($dados['xml_path']) ? 'XML guardado no pedido.' : 'XML não disponível.');

        return $this->timeline->record(
            $pedido,
            OrderFulfillmentEvent::STEP_INVOICE_CANCELLED,
            OrderFulfillmentEvent::STATUS_SUCCESS,
            $mensagem,
            ['cancelada' => true, 'chave' => $chave] + $dados,
        );
    }

    private function xmlGuardado(?string $caminho): ?string
    {
        return $caminho && Storage::disk('local')->exists($caminho) ? $caminho : null;
    }

    /** "Se tiver, baixa o XML": nota do Bling sem XML guardado deste lado. */
    private function baixarXmlDoBling(Order $pedido, string $plataforma, string $chave): ?string
    {
        if ($plataforma !== 'Bling') {
            return null;
        }

        $conteudo = rescue(fn () => app(BlingInvoiceImporter::class)->baixarXml($chave), null, false);

        if (! $conteudo) {
            return null;
        }

        $caminho = "invoices/{$pedido->id}/nfe-{$chave}.xml";
        Storage::disk('local')->put($caminho, $conteudo);

        return $caminho;
    }
}
