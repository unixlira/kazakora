<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Jobs\CheckShipmentLabelJob;
use App\Modules\Marketplace\Models\ChannelShipment;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Confirma/consulta o método de envio no canal (a decisão em si — Flex x
 * padrão no Mercado Livre, drop-off na Shopee — é feita pelo próprio canal
 * ou já decidida automaticamente; ver MarketplaceChannelDriver::confirmShipping()).
 * Dispara direto na importação do pedido pago (OrderImportService), em
 * paralelo com a nota fiscal — não depende dela.
 */
class ChannelShippingService
{
    public function __construct(
        private readonly MarketplaceDriverManager $manager,
        private readonly OrderFulfillmentTimeline $timeline,
    ) {
    }

    public function confirm(Order $order): ChannelShipment
    {
        $shipment = ChannelShipment::query()->firstOrCreate(
            ['order_id' => $order->id, 'channel' => $order->origin],
            ['status' => ChannelShipment::STATUS_PENDING],
        );

        try {
            $result = $this->manager->driver($order->origin)->confirmShipping($order);
        } catch (Throwable $exception) {
            $shipment->update(['status' => ChannelShipment::STATUS_ERROR, 'error_message' => $exception->getMessage()]);
            $this->timeline->record($order, OrderFulfillmentEvent::STEP_SHIPPING_CONFIRMED, OrderFulfillmentEvent::STATUS_FAILED, $exception->getMessage());

            throw $exception;
        }

        $scheduledFor = $result['scheduled_for'] ?? null;

        $shipment->update([
            'external_shipment_id' => $result['external_shipment_id'] ?? $shipment->external_shipment_id,
            'tracking_code' => $result['tracking_code'] ?? $shipment->tracking_code,
            'shipping_method' => $result['shipping_method'],
            'status' => ChannelShipment::STATUS_CONFIRMED,
            'confirmed_at' => now(),
            'scheduled_for' => $scheduledFor,
            // Achado real 2026-08-08 (pedido #189): uma tentativa anterior
            // que falhou grava error_message; sem limpar aqui, uma
            // tentativa seguinte que dá certo deixava esse texto de erro
            // velho pra sempre na tela do pedido, mesmo com status
            // "confirmed" — parecia um problema que já tinha sido resolvido.
            'error_message' => null,
        ]);

        $message = $scheduledFor
            ? "Método: {$result['shipping_method']} — venda agendada, etiqueta só é liberada perto de {$scheduledFor->format('d/m/Y')}"
            : "Método: {$result['shipping_method']}";

        $this->timeline->record($order, OrderFulfillmentEvent::STEP_SHIPPING_CONFIRMED, OrderFulfillmentEvent::STATUS_SUCCESS, $message);

        // Dispara o retry orientado a evento assim que o envio existe do
        // lado do canal — não espera o próximo webhook nem um ciclo de
        // polling. Mercado Livre, Shopee e Amazon têm fetchLabel() real
        // implementado; TikTok/Shein ainda são stubs — disparar lá só
        // geraria falha garantida após 4h de tentativas inúteis.
        if (in_array($order->origin, [Order::ORIGIN_MERCADO_LIVRE, Order::ORIGIN_SHOPEE, Order::ORIGIN_AMAZON], true)) {
            // BUG REAL 2026-08-14 (pedido #278): venda agendada (ver
            // MercadoLivreDriver::extractScheduledFor()) disparando o job
            // padrão martelava a API a cada 5s por até 4h só pra ouvir "não
            // pronta" sempre — o canal já avisou de propósito que só libera
            // perto de scheduled_for, insistir antes disso é desperdício e
            // ainda gera um alerta de "falhou" falso pros admins quando o
            // prazo de 4h vence sem sucesso (esperado, não é erro nenhum).
            // Atrasa o início do job pra perto da data (2h antes, margem
            // pro canal liberar um pouco cedo) e estende o prazo pra 24h
            // DEPOIS da data agendada, em vez das 4h padrão a partir de
            // agora.
            if ($scheduledFor) {
                $startAt = Carbon::now()->max($scheduledFor->clone()->subHours(2));
                $deadline = $scheduledFor->clone()->addHours(24)->toImmutable();

                CheckShipmentLabelJob::dispatch($shipment->id, $deadline)->delay($startAt)->afterCommit();
            } else {
                CheckShipmentLabelJob::dispatch($shipment->id)->afterCommit();
            }
        }

        return $shipment;
    }
}
