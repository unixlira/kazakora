<?php

namespace App\Services\MercadoLivre\Services;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\FlexControlService;
use App\Modules\Marketplace\Support\OrderImportService;
use App\Services\MercadoLivre\MercadoLivreClient;
use Illuminate\Support\Carbon;
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

        // Qualquer um dos pedidos do envio serve de ponto de partida:
        // syncOrderStatusFromShipment() aplica o status em todos os pedidos
        // que dividem este envio (carrinho).
        $shipment = ChannelShipment::query()
            ->where('channel', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)
            ->where('external_shipment_id', $shipmentId)
            ->whereHas('order')
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

        // BUG REAL 2026-09-11: carrinho do Mercado Livre é N pedidos com o
        // MESMO envio, e isto só atualizava o pedido do ChannelShipment que
        // chegou aqui (o webhook pegava o primeiro com ->first()). O irmão
        // ficava "pago" pra sempre: #894, #1300 e #1368 entregues e #1541
        // enviado continuavam na fila, e o card do carrinho no KoraSync se
        // partia (o agrupamento separa por status). O envio é um só — o
        // status dele vale pra todos os pedidos que vão nele, com UMA
        // consulta à API.
        $doEnvio = ChannelShipment::query()
            ->where('channel', $shipment->channel)
            ->where('external_shipment_id', $shipment->external_shipment_id)
            ->with('order')
            ->get()
            ->filter(fn (ChannelShipment $item) => $item->order !== null);

        if ($doEnvio->isEmpty()) {
            $doEnvio = collect([$shipment]);
        }

        foreach ($doEnvio as $item) {
            $this->gravarStatusDoCanal($item, $raw);
        }

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
            foreach ($doEnvio as $item) {
                // Pedido do carrinho cancelado sozinho (comprador desistiu de
                // um item) não "volta" por causa do envio dos outros: o item
                // dele não foi na caixa.
                if ($item->order->status === Order::STATUS_CANCELLED) {
                    continue;
                }

                $this->importer->syncStatus($item->order, $newOrderStatus, $substatus ?? $raw['status'] ?? null);
            }
        }

        // Depois do syncStatus de propósito: o alerta olha o status do
        // pedido já atualizado (entregue, cancelado...). Só no envio que
        // chegou aqui, como sempre — reavaliar cada pedido do carrinho
        // mandaria o mesmo alerta de Flex uma vez por pedido.
        if ($shipment->shipping_method === ChannelShipment::METHOD_FLEX) {
            app(FlexControlService::class)->reavaliar($shipment->refresh());
        }
    }

    /**
     * Guarda o que o canal diz do envio — status, substatus e as datas do
     * `status_history` — em vez de jogar fora depois de decidir o status
     * do pedido.
     *
     * Existe por causa da bicicleta do pedido #1384 (2026-09-11): o único
     * sinal de que ela saiu com o entregador sem ele iniciar a rota era o
     * `date_shipped` vazio numa venda cancelada — e ninguém guardava isso.
     * Falhar aqui nunca pode derrubar a sincronização do pedido.
     *
     * @param  array<string, mixed>  $raw
     */
    private function gravarStatusDoCanal(ChannelShipment $shipment, array $raw): void
    {
        try {
            $historico = is_array($raw['status_history'] ?? null) ? $raw['status_history'] : [];

            $data = function (string $chave) use ($historico): ?Carbon {
                $valor = $historico[$chave] ?? null;

                return $valor ? Carbon::parse($valor)->setTimezone(config('app.timezone')) : null;
            };

            $shipment->forceFill([
                'channel_status' => isset($raw['status']) ? mb_substr((string) $raw['status'], 0, 40) : null,
                'channel_substatus' => isset($raw['substatus']) ? mb_substr((string) $raw['substatus'], 0, 60) : null,
                'channel_shipped_at' => $data('date_shipped'),
                'channel_first_visit_at' => $data('date_first_visit'),
                'channel_delivered_at' => $data('date_delivered'),
                'channel_not_delivered_at' => $data('date_not_delivered'),
                'channel_returned_at' => $data('date_returned'),
                'channel_cancelled_at' => $data('date_cancelled'),
                'channel_status_checked_at' => now(),
            ])->save();
        } catch (\Throwable $exception) {
            Log::channel(config('mercadolivre.log_channel'))->warning('mercadolivre.shipment.status_canal_nao_gravado', [
                'shipment_id' => $shipment->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
