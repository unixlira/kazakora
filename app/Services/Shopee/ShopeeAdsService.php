<?php

namespace App\Services\Shopee;

use Illuminate\Support\Carbon;

/**
 * Shopee Ads (Product Ads / CPC) — confirmado ao vivo 2026-08-09 contra a
 * loja real: /api/v2/ads/get_all_cpc_ads_daily_performance já devolve
 * gasto/impressão/clique/venda atribuída agregado por dia pra loja
 * inteira, sem precisar de permissão especial nenhuma (ao contrário do que
 * a documentação pública sugere sobre "Ads requer aprovação"). Datas vão
 * em DD-MM-YYYY — formato confirmado pela própria mensagem de erro da API
 * quando testado com Y-m-d.
 */
class ShopeeAdsService
{
    public function __construct(private readonly ShopeeClient $client)
    {
    }

    /**
     * @return array<int, array{date: string, impressions: int, clicks: int, attributed_orders: int, attributed_gmv: float, spend: float}>
     */
    public function dailyPerformance(Carbon $from, Carbon $to): array
    {
        $response = $this->client->get('/api/v2/ads/get_all_cpc_ads_daily_performance', [
            'start_date' => $from->format('d-m-Y'),
            'end_date' => $to->format('d-m-Y'),
        ]);

        $rows = $response['response'] ?? [];

        return array_map(function (array $row) {
            // broad_* (não direct_*) é a atribuição mais ampla que a
            // Shopee já calcula sozinha — é o número que aparece no painel
            // de Ads deles como "vendas do anúncio", o que os vendedores
            // já esperam ver aqui também.
            return [
                'date' => Carbon::createFromFormat('d-m-Y', $row['date'])->toDateString(),
                'impressions' => (int) ($row['impression'] ?? 0),
                'clicks' => (int) ($row['clicks'] ?? 0),
                'attributed_orders' => (int) ($row['broad_order'] ?? 0),
                'attributed_gmv' => (float) ($row['broad_gmv'] ?? 0),
                'spend' => (float) ($row['expense'] ?? 0),
            ];
        }, $rows);
    }
}
