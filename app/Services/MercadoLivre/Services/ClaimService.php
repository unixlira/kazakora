<?php

namespace App\Services\MercadoLivre\Services;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\MarketplaceClaim;
use App\Services\MercadoLivre\MercadoLivreClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reclamações/devoluções do Mercado Livre — tópico `post_purchase`, nunca
 * processado antes (WebhookHandler marcava como "ignored"). Payload real
 * confirmado ao vivo 2026-08-05: `resource: /post-purchase/v1/claims/{id}`,
 * `actions: ["claims"]`. Pedido explícito do usuário: só rastrear/mostrar
 * (nova tela Devoluções) — NÃO mexe em estoque nem em receita sozinho, isso
 * fica manual (ver botão "Reverter estoque" em
 * MercadoLivreClaimsController::revertStock(), que reaproveita
 * OrderPaymentFinalizer::restoreStockIfNeeded() já existente).
 */
class ClaimService
{
    public function __construct(private readonly MercadoLivreClient $client) {}

    /**
     * @return array<string, mixed>
     */
    public function getClaim(string $claimId): array
    {
        return $this->client->get("post-purchase/v1/claims/{$claimId}");
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function processWebhook(array $payload): void
    {
        Log::channel(config('mercadolivre.log_channel'))->info('mercadolivre.webhook.post_purchase', $payload);

        if (! preg_match('#/claims/(\d+)#', $payload['resource'] ?? '', $matches)) {
            Log::channel(config('mercadolivre.log_channel'))->warning('mercadolivre.webhook.post_purchase.unparseable_resource', $payload);

            return;
        }

        $claimId = $matches[1];

        try {
            $claim = $this->getClaim($claimId);
        } catch (Throwable $exception) {
            // Território novo, endpoint nunca chamado em produção até
            // agora — se a API recusar (claim expirado, permissão,
            // formato inesperado), loga e desiste dessa notificação em vez
            // de derrubar o worker. O Mercado Livre reenvia webhooks (até 8
            // tentativas/1h) e claims recebem várias notificações ao longo
            // da vida (aberto → mediação → resolvido), então uma falha
            // pontual não perde o rastro do claim pra sempre.
            Log::channel(config('mercadolivre.log_channel'))->error('mercadolivre.webhook.post_purchase.fetch_failed', [
                'claim_id' => $claimId,
                'message' => $exception->getMessage(),
            ]);

            return;
        }

        // resource_id é o id do pedido no Mercado Livre quando resource ==
        // "order" (documentado) — mesmo vocabulário que
        // orders.external_order_id já usa pra qualquer outro claim vindo
        // desse canal.
        $orderId = null;
        if (($claim['resource'] ?? null) === 'order' && ! empty($claim['resource_id'])) {
            $orderId = Order::query()
                ->where('origin', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)
                ->where('external_order_id', (string) $claim['resource_id'])
                ->value('id');
        }

        MarketplaceClaim::query()->updateOrCreate(
            ['channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE, 'external_claim_id' => $claimId],
            [
                'order_id' => $orderId,
                'type' => $claim['type'] ?? null,
                'stage' => $claim['stage'] ?? null,
                'status' => $claim['status'] ?? null,
                'reason_id' => $claim['reason_id'] ?? null,
                'resolution' => $claim['resolution'] ?? null,
                'raw_payload' => $claim,
                'claim_created_at' => $claim['date_created'] ?? null,
                'claim_updated_at' => $claim['last_updated'] ?? null,
            ],
        );
    }
}
