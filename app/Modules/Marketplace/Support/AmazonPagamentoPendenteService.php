<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Marketplace\Models\AmazonPagamentoPendente;
use App\Services\Amazon\AmazonClient;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Regra do Lira (2026-10-16) para pedido da Amazon com pagamento pendente
 * (boleto aguardando):
 *
 * 1. Guarda o pedido completo da SP-API (getOrder + orderItems).
 * 2. Enquanto pendente, fica em "aguardando pagamento": sem NF-e, sem
 *    etiqueta e fora da fila do KoraSync (só pedido pago entra em
 *    GenerateInvoiceJob/ConfirmChannelShippingJob e em
 *    DashboardAgentController::queue()).
 * 3. Confere o status na Amazon de 24 em 24 horas.
 * 4. Cancelado na Amazon: cancela aqui (devolve estoque).
 * 5. Pago: vira "pago" e OrderImportService::syncStatus() dispara NF-e e
 *    etiqueta; o rastreio sobe pela SP-API em amazon:confirmar-envios.
 * 6. Ainda pendente no 8º dia: cancela só no KazaKora (o Lira estendeu de
 *    7 para 8 dias para dar tempo do boleto compensar). Se a Amazon
 *    confirmar o pagamento depois, a varredura reabre o pedido como pago
 *    (OrderImportService::isStaleStatus() aceita cancelado → pago).
 *
 * Na dúvida, segura: SP-API fora do ar não libera nem cancela nada.
 */
class AmazonPagamentoPendenteService
{
    public const HORAS_ENTRE_CONFERENCIAS = 24;

    public const DIAS_PARA_CANCELAR = 8;

    /** OrderStatus da SP-API que só existe depois do pagamento aprovado. */
    public const PAGOS = ['Unshipped', 'PartiallyShipped', 'Shipped', 'InvoiceUnconfirmed'];

    public const CANCELADOS = ['Canceled', 'Unfulfillable'];

    public function __construct(
        private readonly AmazonClient $client,
        private readonly OrderImportService $importer,
        private readonly OrderFulfillmentTimeline $timeline,
    ) {}

    /**
     * Já é hora de conferir? Nunca conferido, 24 h desde a última ou
     * chegou o dia de cancelar (confere antes, o boleto pode ter caído).
     */
    public function precisaConferir(Order $order): bool
    {
        $registro = AmazonPagamentoPendente::query()->where('order_id', $order->id)->first();

        return ! $registro?->conferido_em
            || $registro->conferido_em->lte(now()->subHours(self::HORAS_ENTRE_CONFERENCIAS))
            || $this->venceuPrazo($order);
    }

    /**
     * @return string o que aconteceu: pago, cancelado_na_amazon,
     *                cancelado_por_prazo ou pendente
     */
    public function conferir(Order $order): string
    {
        $amazonOrderId = (string) $order->external_order_id;
        $pedido = $this->client->get("/orders/v0/orders/{$amazonOrderId}")['payload'] ?? null;
        $status = is_array($pedido) ? ($pedido['OrderStatus'] ?? null) : null;

        if (! is_string($status) || $status === '') {
            throw new RuntimeException("A Amazon não devolveu o status do pedido {$amazonOrderId}.");
        }

        $registro = AmazonPagamentoPendente::firstOrNew(['order_id' => $order->id]);

        if (! $registro->exists || ! isset($registro->pedido_amazon['itens'])) {
            $pedido = ['pedido' => $pedido, 'itens' => $this->client->get("/orders/v0/orders/{$amazonOrderId}/orderItems")['payload'] ?? null];
        } else {
            $pedido = [...$registro->pedido_amazon, 'pedido' => $pedido];
        }

        $registro->fill([
            'amazon_order_id' => $amazonOrderId,
            'status_amazon' => $status,
            'pedido_amazon' => $pedido,
            'pendente_desde' => $registro->pendente_desde ?? $order->created_at,
            'conferido_em' => now(),
            'conferencias' => $registro->conferencias + 1,
        ])->save();

        if (in_array($status, self::PAGOS, true)) {
            $this->resolver($registro, AmazonPagamentoPendente::RESULTADO_PAGO);
            $this->timeline->record($order, OrderFulfillmentEvent::STEP_WEBHOOK_RECEIVED, OrderFulfillmentEvent::STATUS_SUCCESS, "Amazon confirmou o pagamento (\"{$status}\"): NF-e e etiqueta liberadas.");
            $this->importer->syncStatus($order, Order::STATUS_PAID, $status);

            return AmazonPagamentoPendente::RESULTADO_PAGO;
        }

        if (in_array($status, self::CANCELADOS, true)) {
            $this->resolver($registro, AmazonPagamentoPendente::RESULTADO_CANCELADO_AMAZON);
            $this->timeline->record($order, OrderFulfillmentEvent::STEP_WEBHOOK_RECEIVED, OrderFulfillmentEvent::STATUS_SUCCESS, "Pedido cancelado na Amazon (\"{$status}\") com o pagamento ainda pendente.");
            $this->importer->syncStatus($order, Order::STATUS_CANCELLED, $status);

            return AmazonPagamentoPendente::RESULTADO_CANCELADO_AMAZON;
        }

        if ($this->venceuPrazo($order)) {
            $this->resolver($registro, AmazonPagamentoPendente::RESULTADO_CANCELADO_PRAZO);
            $this->timeline->record($order, OrderFulfillmentEvent::STEP_WEBHOOK_RECEIVED, OrderFulfillmentEvent::STATUS_SUCCESS, 'Cancelado no KazaKora: '.self::DIAS_PARA_CANCELAR." dias sem pagamento. Na Amazon continua \"{$status}\" (não foi cancelado lá).");
            $this->importer->syncStatus($order, Order::STATUS_CANCELLED, $status);

            Log::info('amazon.pagamento_pendente.cancelado_por_prazo', ['order_id' => $order->id, 'amazon_order_id' => $amazonOrderId, 'status_amazon' => $status]);

            return AmazonPagamentoPendente::RESULTADO_CANCELADO_PRAZO;
        }

        $this->timeline->record($order, OrderFulfillmentEvent::STEP_WEBHOOK_RECEIVED, OrderFulfillmentEvent::STATUS_PENDING, "Pagamento ainda pendente na Amazon (\"{$status}\"): NF-e, etiqueta e KoraSync seguem bloqueados.");

        return 'pendente';
    }

    private function venceuPrazo(Order $order): bool
    {
        return $order->created_at->lte(now()->subDays(self::DIAS_PARA_CANCELAR));
    }

    private function resolver(AmazonPagamentoPendente $registro, string $resultado): void
    {
        $registro->update(['resolvido_em' => now(), 'resultado' => $resultado]);
    }
}
