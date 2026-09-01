<?php

namespace App\Services\MercadoLivre\Services;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\OrderImportService;
use App\Services\MercadoLivre\MercadoLivreClient;
use Illuminate\Support\Facades\Log;

class ShipmentService
{
    public function __construct(
        private readonly MercadoLivreClient $client,
        private readonly OrderImportService $importer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getShipment(string $shipmentId): array
    {
        return $this->client->get("shipments/{$shipmentId}");
    }

    /**
     * @return array<string, mixed>
     */
    public function getTrackingInfo(string $shipmentId): array
    {
        return $this->client->get("shipments/{$shipmentId}/history");
    }

    /**
     * @return array<string, mixed>
     */
    public function updateShipmentStatus(string $shipmentId, string $status): array
    {
        return $this->client->put("shipments/{$shipmentId}", ['status' => $status]);
    }

    /**
     * BUG REAL 2026-08-17 (achado investigando "por que a etiqueta pronta
     * no ML não aparece aqui"): este método só logava o payload e não
     * fazia nada — nenhum pedido do Mercado Livre jamais avançava pra
     * "shipped"/"completed" via webhook. Confirmado ao vivo: 18 pedidos
     * reais já estavam com shipment.status="delivered" (entregues há
     * semanas) direto na API do ML, mas continuavam "paid" no nosso banco
     * pra sempre — porque, diferente da Shopee (ShopeeDriver::
     * mapOrderStatus() lê order_status direto do pedido), o Mercado Livre
     * NUNCA muda o status de nível PEDIDO pra refletir entrega — essa
     * informação só existe no sub-recurso SHIPMENT, e só chega aqui via
     * este webhook (topic=shipments). Sem processar de verdade, o pedido
     * ficava "pago, aguardando envio" pra sempre em toda fila do sistema,
     * mesmo já entregue de verdade.
     *
     * Consulta o shipment real (o payload do webhook não traz o status,
     * só avisa "isso mudou" — mesmo padrão de OrderService::
     * processWebhook(), que também precisa reconsultar o recurso completo)
     * e avança Order::status só pra shipped/delivered — nunca regride
     * (OrderImportService::syncStatus() já tem essa trava, canal-
     * agnóstica) e ignora silenciosamente qualquer outro status
     * intermediário (pending/handling/ready_to_ship) ou webhook que chegou
     * antes do nosso ChannelShipment existir (ChannelShippingService::
     * confirm() ainda não rodou pra esse pedido — não é erro, só ainda
     * não é hora).
     *
     * @param  array<string, mixed>  $payload
     */
    public function processWebhook(array $payload): void
    {
        Log::channel(config('mercadolivre.log_channel'))->info('mercadolivre.webhook.shipments', $payload);

        if (! preg_match('#/shipments/(\d+)#', $payload['resource'] ?? '', $matches)) {
            Log::channel(config('mercadolivre.log_channel'))->warning('mercadolivre.webhook.shipments.unparseable_resource', $payload);

            return;
        }

        $shipmentId = $matches[1];

        $shipment = ChannelShipment::query()
            ->where('channel', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)
            ->where('external_shipment_id', $shipmentId)
            ->first();

        if (! $shipment || ! $shipment->order) {
            Log::channel(config('mercadolivre.log_channel'))->info('mercadolivre.webhook.shipments.unknown_shipment', ['shipment_id' => $shipmentId]);

            return;
        }

        $this->syncOrderStatusFromShipment($shipment);
    }

    /**
     * Extraído de processWebhook() (pedido explícito 2026-08-29, "manter
     * uma rotina de verificação periódica como garantia") — mesma consulta
     * ao shipment real + mapeamento shipped/delivered, agora reaproveitável
     * por um polling agendado (ver App\Console\Commands\
     * PollMercadoLivreShipmentStatuses) além do webhook. O Mercado Livre
     * nunca reflete entrega no pedido em si (só no sub-recurso shipment,
     * ver comentário completo em processWebhook() acima), então sem essa
     * rotina de garantia um webhook perdido deixaria o pedido pago pra
     * sempre, mesmo já entregue de verdade.
     */
    public function syncOrderStatusFromShipment(ChannelShipment $shipment): void
    {
        if (! $shipment->external_shipment_id || ! $shipment->order) {
            return;
        }

        $raw = $this->getShipment($shipment->external_shipment_id);

        $substatus = $raw['substatus'] ?? null;

        $newOrderStatus = match (true) {
            ($raw['status'] ?? null) === 'delivered' => Order::STATUS_COMPLETED,
            ($raw['status'] ?? null) === 'shipped' => Order::STATUS_SHIPPED,
            // BUG REAL 2026-09-01 (relatado pelo usuário: "pedidos já
            // bipados na agência hoje e ainda estão no korasync"): em
            // Agência/Drop off (logistic_type drop_off/xd_drop_off) o
            // Mercado Livre marca o recebimento no BALCÃO só no substatus
            // — o shipment fica em `ready_to_ship` com substatus
            // `picked_up` (e date_shipped ainda null) por horas até virar
            // `shipped` de verdade. Como o mapeamento só olhava o status,
            // o pacote já entregue na agência continuava na fila de
            // separação do KoraSync como se ainda estivesse na
            // prateleira. `printed` (etiqueta impressa, pacote ainda
            // aqui) continua de fora, que é a diferença que importa.
            $substatus === 'picked_up' => Order::STATUS_SHIPPED,
            default => null,
        };

        if ($newOrderStatus !== null) {
            $this->importer->syncStatus($shipment->order, $newOrderStatus, $substatus ?? $raw['status'] ?? null);
        }
    }
}
