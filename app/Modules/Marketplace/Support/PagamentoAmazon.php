<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Services\Amazon\AmazonClient;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Regra do Lira (2026-10-16): pedido da Amazon só é expedido (NF-e,
 * etiqueta, fila do KoraSync) com o pagamento CONFIRMADO na Amazon.
 *
 * O pedido entra pelo Bling (AmazonDriver::viaBling()), e lá a situação
 * "Em aberto" virava "pago" aqui sem ninguém olhar a Amazon — um pedido
 * ainda "Pending" (pagamento não aprovado) ganhava nota, etiqueta e ia pra
 * bancada. Agora o status de verdade vem da SP-API (getOrder.OrderStatus).
 *
 * Na dúvida, segura: SP-API fora do ar ou desconectada não libera nada.
 * Quem segura não perde o pedido — quando a Amazon confirmar, a varredura
 * de hora em hora (orders:sync-amazon) traz "pago" e
 * OrderImportService::syncStatus() dispara nota e etiqueta de novo.
 */
class PagamentoAmazon
{
    /**
     * Vocabulário de OrderStatus da SP-API. A Amazon só libera o pedido pro
     * vendedor ("Unshipped") depois de aprovar o pagamento; daí pra frente
     * é tudo venda paga.
     */
    public const PAGOS = ['Unshipped', 'PartiallyShipped', 'Shipped', 'InvoiceUnconfirmed'];

    public const CANCELADOS = ['Canceled', 'Unfulfillable'];

    public function __construct(
        private readonly AmazonClient $client,
        private readonly OrderFulfillmentTimeline $timeline,
    ) {}

    /**
     * OrderStatus atual na Amazon. Lança se não der pra saber (SP-API
     * desconectada, erro da API, resposta sem status): quem chama decide
     * tentar de novo, nunca liberar.
     */
    public function statusNaAmazon(string $amazonOrderId): string
    {
        $status = $this->client->get("/orders/v0/orders/{$amazonOrderId}")['payload']['OrderStatus'] ?? null;

        if (! is_string($status) || $status === '') {
            throw new RuntimeException("A Amazon não devolveu o status do pedido {$amazonOrderId}: pagamento não conferido.");
        }

        return $status;
    }

    public static function pago(string $status): bool
    {
        return in_array($status, self::PAGOS, true);
    }

    /**
     * Status do KazaKora pelo pagamento real na Amazon.
     */
    public static function statusDoPedido(string $status): string
    {
        return match (true) {
            self::pago($status) => Order::STATUS_PAID,
            in_array($status, self::CANCELADOS, true) => Order::STATUS_CANCELLED,
            default => Order::STATUS_AWAITING_PAYMENT,
        };
    }

    /**
     * Confere o pagamento antes de uma etapa da expedição. true = segurou:
     * a etapa NÃO pode seguir. Pedido pago aqui e pendente lá volta pra
     * "aguardando pagamento" (sai da fila do KoraSync); cancelado lá é
     * cancelado aqui pelo caminho de sempre (devolve estoque).
     *
     * Lança se não conseguir conferir (ver statusNaAmazon()).
     */
    public function segurarExpedicao(Order $order, string $etapa, string $passo): bool
    {
        if ($order->origin !== Order::ORIGIN_AMAZON || ! $order->external_order_id) {
            return false;
        }

        $status = $this->statusNaAmazon($order->external_order_id);

        if (self::pago($status)) {
            return false;
        }

        if (in_array($status, self::CANCELADOS, true)) {
            app(OrderImportService::class)->syncStatus($order, Order::STATUS_CANCELLED, $status);
        } elseif ($order->status === Order::STATUS_PAID) {
            $order->update(['status' => Order::STATUS_AWAITING_PAYMENT]);
        }

        Log::channel('amazon')->warning('amazon.expedicao_segurada_pagamento', [
            'order_id' => $order->id,
            'amazon_order_id' => $order->external_order_id,
            'etapa' => $etapa,
            'status_amazon' => $status,
        ]);

        $this->timeline->record(
            $order,
            $passo,
            OrderFulfillmentEvent::STATUS_FAILED,
            "{$etapa} bloqueada: o pedido está \"{$status}\" na Amazon (pagamento não confirmado). Fica fora da fila do KoraSync e volta sozinho quando a Amazon confirmar o pagamento.",
        );

        return true;
    }
}
