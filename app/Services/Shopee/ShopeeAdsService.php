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
     * Saldo atual de anúncio — confirmado ao vivo (get_total_balance,
     * 2026-08-09). É só o saldo de AGORA, a Shopee não expõe extrato de
     * recarga consultável (testado via get_wallet_transaction_list: traz
     * transação de venda real, nenhuma de recarga de ads).
     */
    public function currentBalance(): float
    {
        $response = $this->client->get('/api/v2/ads/get_total_balance');

        return (float) ($response['response']['total_balance'] ?? 0);
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

    /**
     * Ids de todas as campanhas de ADS por produto (manual e automática),
     * de qualquer status. Conferido ao vivo em 2026-10-14: 96 campanhas.
     *
     * @return list<int>
     */
    public function productCampaignIds(): array
    {
        $ids = [];
        for ($offset = 0; $offset < 5000; $offset += 100) {
            $res = $this->client->get('/api/v2/ads/get_product_level_campaign_id_list', ['offset' => $offset, 'limit' => 100])['response'] ?? [];
            foreach ($res['campaign_list'] ?? [] as $c) {
                $ids[] = (int) $c['campaign_id'];
            }
            if (empty($res['has_next_page'])) {
                break;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Configuração de até 100 campanhas: common_info (nome, status, lance,
     * orçamento, itens) e auto_bidding_info.roas_target.
     *
     * @param  list<int>  $ids
     */
    public function productCampaignSettings(array $ids): array
    {
        return $this->client->get('/api/v2/ads/get_product_level_campaign_setting_info', [
            'campaign_id_list' => implode(',', $ids),
            'info_type_list' => '1,2,3,4',
        ])['response']['campaign_list'] ?? [];
    }

    /**
     * Desempenho diário de até 100 campanhas. Datas em DD-MM-YYYY; a mesma
     * janela de 30 dias que o diagnóstico do Cauê já usa.
     *
     * @param  list<int>  $ids
     * @return list<array{campaign_id: int, date: string, impressions: int, clicks: int, spend: float, broad_orders: int, broad_units: int, broad_gmv: float, direct_orders: int, direct_gmv: float}>
     */
    public function productCampaignDailyPerformance(array $ids, Carbon $from, Carbon $to): array
    {
        $campanhas = $this->client->get('/api/v2/ads/get_product_campaign_daily_performance', [
            'campaign_id_list' => implode(',', $ids),
            'start_date' => $from->format('d-m-Y'),
            'end_date' => $to->format('d-m-Y'),
        ])['response']['campaign_list'] ?? [];

        $linhas = [];
        foreach ($campanhas as $c) {
            foreach ($c['metrics_list'] ?? [] as $d) {
                $linhas[] = [
                    'campaign_id' => (int) $c['campaign_id'],
                    'date' => Carbon::createFromFormat('d-m-Y', $d['date'])->toDateString(),
                    'impressions' => (int) ($d['impression'] ?? 0),
                    'clicks' => (int) ($d['clicks'] ?? 0),
                    'spend' => (float) ($d['expense'] ?? 0),
                    'broad_orders' => (int) ($d['broad_order'] ?? 0),
                    'broad_units' => (int) ($d['broad_order_amount'] ?? 0),
                    'broad_gmv' => (float) ($d['broad_gmv'] ?? 0),
                    'direct_orders' => (int) ($d['direct_order'] ?? 0),
                    'direct_gmv' => (float) ($d['direct_gmv'] ?? 0),
                ];
            }
        }

        return $linhas;
    }
}
