<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Checkout\Models\Order;
use App\Services\MercadoLivre\MercadoLivreClient;

/**
 * Pedido do Mercado Livre que sai do Full (estoque no armazém do ML).
 *
 * No Full quem fatura é o próprio Mercado Livre: a nota nossa é recusada
 * ("Access denied, you must use the biller of MercadoLibre") e fica uma
 * segunda NF-e autorizada para a mesma venda. Caso real 2026-11-04: pedidos
 * #3811, #3812, #3813 e #3818 saíram com as notas 2-3478 a 2-3481, todas
 * recusadas pelo ML; o mesmo em 130 pedidos de ago-set/2026. Regra do Lira
 * (04/11): pedido do Full não emite nota no KazaKora.
 */
class MercadoLivreFull
{
    public const LOGISTIC_TYPE = 'fulfillment';

    public function __construct(private readonly MercadoLivreClient $client)
    {
    }

    /**
     * O tipo de envio gravado (ConfirmChannelShippingJob) vale primeiro. Os
     * dois jobs saem juntos da importação, então a nota pode chegar antes do
     * envio gravado: aí pergunta ao ML. Erro na consulta lança e o job da
     * nota tenta de novo; nunca emite sem saber.
     */
    public function ehFull(Order $order): bool
    {
        if ($order->origin !== Order::ORIGIN_MERCADO_LIVRE || ! $order->external_order_id) {
            return false;
        }

        $order->loadMissing('channelShipment');
        $gravado = $order->channelShipment?->shipping_method;

        if ($gravado) {
            return $gravado === self::LOGISTIC_TYPE;
        }

        $envio = $this->client->get("orders/{$order->external_order_id}")['shipping']['id'] ?? null;

        // Venda sem envio do ML (frete a combinar) não sai do armazém deles.
        if (! $envio) {
            return false;
        }

        $shipment = $this->client->get("shipments/{$envio}");

        return ($shipment['logistic_type'] ?? $shipment['logistic']['type'] ?? null) === self::LOGISTIC_TYPE;
    }
}
