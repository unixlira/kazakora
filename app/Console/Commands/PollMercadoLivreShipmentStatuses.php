<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Services\MercadoLivre\Services\ShipmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rotina de verificação periódica (pedido explícito 2026-08-29: "preferir
 * webhook quando disponível, mas manter uma rotina de verificação periódica
 * como garantia") — reconsulta o shipment real de todo pedido do Mercado
 * Livre ainda "pago" (não avançou pra shipped/delivered/cancelled) e aplica
 * o mesmo mapeamento shipped->STATUS_SHIPPED / delivered->STATUS_COMPLETED
 * que o webhook (topic=shipments) já aplica — ver ShipmentService::
 * syncOrderStatusFromShipment(), extraído de processWebhook() pra ser
 * reaproveitado aqui.
 *
 * Só existe pro Mercado Livre porque só ele precisa: a Shopee já reflete
 * shipped/completed no status do PEDIDO em si (ShopeeDriver::
 * mapOrderStatus()), então a resincronização horária já existente
 * (orders:sync-shopee) já cobre esse caso de "garantia" sem precisar de
 * nada novo. O Mercado Livre NUNCA muda o status do pedido pra refletir
 * entrega (só o sub-recurso shipment, ver comentário completo em
 * ShipmentService::processWebhook()) — sem esta rotina, um webhook perdido
 * deixaria o pedido "pago" pra sempre, mesmo já coletado/entregue de
 * verdade.
 *
 * Escopo: só pedido ainda PAID (uma vez que sai desse status — shipped,
 * completed ou cancelled — some da query sozinho, não precisa de trava
 * manual) com envio confirmado no Mercado Livre há no máximo 60 dias
 * (mesmo espírito do corte mensal de OutOfStock em
 * DashboardAgentController::queue() — não é auditoria de arquivo morto,
 * é garantia operacional do que ainda está de fato em aberto).
 */
class PollMercadoLivreShipmentStatuses extends Command
{
    protected $signature = 'orders:poll-mercadolivre-shipment-status';

    protected $description = 'Reconsulta o shipment real de pedidos pagos do Mercado Livre e avança o status pra shipped/completed quando o canal já confirmou a coleta/entrega (garantia além do webhook)';

    public function handle(ShipmentService $shipments): int
    {
        $account = MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)->first();

        if (! $account?->isConnected()) {
            $this->info('Mercado Livre não está conectado — nada a fazer.');

            return self::SUCCESS;
        }

        $pending = ChannelShipment::query()
            ->where('channel', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)
            ->whereNotNull('external_shipment_id')
            ->where('created_at', '>=', now()->subDays(60))
            ->whereHas('order', fn ($query) => $query->where('status', Order::STATUS_PAID))
            ->with('order')
            ->get();

        $this->info("{$pending->count()} envio(s) do Mercado Livre ainda pago(s) pra reconferir.");

        $checked = 0;
        $failed = 0;

        foreach ($pending as $shipment) {
            try {
                $shipments->syncOrderStatusFromShipment($shipment);
                $checked++;
            } catch (Throwable $exception) {
                $failed++;
                Log::channel(config('mercadolivre.log_channel'))->error('mercadolivre.poll_shipment_status.failed', [
                    'shipment_id' => $shipment->id,
                    'order_id' => $shipment->order_id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $this->info("Concluído: {$checked} reconferido(s), {$failed} com erro.");

        return self::SUCCESS;
    }
}
