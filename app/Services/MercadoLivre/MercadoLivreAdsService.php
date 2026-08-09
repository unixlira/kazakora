<?php

namespace App\Services\MercadoLivre;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Mercado Ads (Product Ads) — confirmado ao vivo 2026-08-09 contra a conta
 * real (KORAMIX, advertiser_id 2670271, 5 campanhas reais). Path certo
 * (achado só na prática, a doc pública engana): NÃO é
 * /advertising/product_ads/campaigns — é
 * /marketplace/advertising/{site_id}/advertisers/{advertiser_id}/product_ads/campaigns/search,
 * com Api-Version: 2. Diferente do MercadoLivreClient (wrapper genérico
 * pras chamadas normais), usa Http direto aqui porque a Advertising API
 * exige o header Api-Version, que o client genérico não expõe.
 */
class MercadoLivreAdsService
{
    private const BASE_URL = 'https://api.mercadolibre.com';

    public function __construct(private readonly MercadoLivreAuthService $auth)
    {
    }

    /**
     * @return int|null null quando a conta não tem cadastro de anunciante
     *                   (nunca criou uma campanha de Product Ads).
     */
    public function resolveAdvertiserId(): ?int
    {
        return Cache::remember('mercadolivre.advertiser_id', now()->addDay(), function () {
            $response = $this->authorizedRequest('1')->get(self::BASE_URL.'/advertising/advertisers', ['product_id' => 'PADS']);

            if ($response->failed()) {
                return null;
            }

            return $response->json('advertisers.0.advertiser_id');
        });
    }

    /**
     * Soma o custo de todas as campanhas ativas/pausadas num único dia —
     * a API devolve métrica agregada por campanha no período pedido, não
     * quebrada por dia sozinha, então um dia por chamada (date_from ===
     * date_to) é o jeito de ter granularidade diária real.
     *
     * @return array{date: string, impressions: int, clicks: int, attributed_orders: int, attributed_gmv: float, spend: float}|null
     *         null quando não há conta de anunciante (nunca usou Mercado Ads).
     */
    public function dailySpend(Carbon $date): ?array
    {
        $advertiserId = $this->resolveAdvertiserId();

        if (! $advertiserId) {
            return null;
        }

        $impressions = 0;
        $clicks = 0;
        $units = 0;
        $amount = 0.0;
        $spend = 0.0;
        $offset = 0;
        $limit = 50;

        do {
            $response = $this->authorizedRequest('2')->get(self::BASE_URL."/marketplace/advertising/MLB/advertisers/{$advertiserId}/product_ads/campaigns/search", [
                'date_from' => $date->toDateString(),
                'date_to' => $date->toDateString(),
                'metrics' => 'clicks,prints,cost,units_quantity',
                'limit' => $limit,
                'offset' => $offset,
            ]);

            if ($response->failed()) {
                throw new RuntimeException("Falha ao consultar campanhas do Mercado Ads: HTTP {$response->status()}.");
            }

            $results = $response->json('results') ?? [];

            foreach ($results as $campaign) {
                $metrics = $campaign['metrics'] ?? [];
                $impressions += (int) ($metrics['prints'] ?? 0);
                $clicks += (int) ($metrics['clicks'] ?? 0);
                $units += (int) ($metrics['units_quantity'] ?? 0);
                $amount += (float) ($metrics['total_amount'] ?? 0);
                $spend += (float) ($metrics['cost'] ?? 0);
            }

            $total = (int) ($response->json('paging.total') ?? 0);
            $offset += $limit;
        } while ($offset < $total);

        return [
            'date' => $date->toDateString(),
            'impressions' => $impressions,
            'clicks' => $clicks,
            'attributed_orders' => $units,
            'attributed_gmv' => round($amount, 2),
            'spend' => round($spend, 2),
        ];
    }

    private function authorizedRequest(string $apiVersion): \Illuminate\Http\Client\PendingRequest
    {
        $token = $this->auth->ensureValidToken($this->auth->currentToken());

        return Http::withToken($token->access_token)->withHeaders(['Api-Version' => $apiVersion]);
    }
}
