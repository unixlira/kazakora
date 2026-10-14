<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ShopeeAdCampanha;
use App\Modules\Marketplace\Models\ShopeeAdCampanhaDia;
use App\Services\Shopee\ShopeeAdsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Estratégia Shopee (menu Publicidade, 2026-10-14): copia as campanhas de
 * ADS por produto e o desempenho diário de cada uma. Só LEITURA da
 * Shopee. Re-sincroniza os últimos dias de propósito (como o
 * ads:sync-spend): o dia corrente continua mudando. Primeira carga:
 * --dias=60.
 */
class SyncShopeeAdCampanhas extends Command
{
    protected $signature = 'shopee:ads-campanhas {--dias=3 : Quantos dias pra trás re-sincronizar, incluindo hoje}';

    protected $description = 'Copia as campanhas de ADS da Shopee e o desempenho diário de cada uma (só leitura)';

    /** Janela por chamada de desempenho, a mesma que o diagnóstico do Cauê usa. */
    private const JANELA = 30;

    public function handle(ShopeeAdsService $ads): int
    {
        $conta = MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)->first();
        if (! $conta?->isConnected()) {
            $this->warn('Shopee não conectada: nada sincronizado.');

            return self::SUCCESS;
        }

        $dias = max(1, (int) $this->option('dias'));
        $ate = Carbon::today();
        $desde = $ate->copy()->subDays($dias - 1);

        try {
            $ids = $ads->productCampaignIds();
        } catch (Throwable $e) {
            $this->error("Shopee Ads: falha ao listar campanhas: {$e->getMessage()}");

            return self::FAILURE;
        }

        $agora = now();
        $campanhas = 0;
        $linhas = 0;
        $falhas = 0;
        foreach (array_chunk($ids, 100) as $lote) {
            try {
                foreach ($ads->productCampaignSettings($lote) as $c) {
                    $this->gravarCampanha($c, $agora);
                    $campanhas++;
                }
            } catch (Throwable $e) {
                $falhas++;
                $this->error("Shopee Ads: falha na configuração das campanhas: {$e->getMessage()}");
            }

            for ($inicio = $desde->copy(); $inicio->lte($ate); $inicio->addDays(self::JANELA)) {
                $fim = $inicio->copy()->addDays(self::JANELA - 1)->min($ate);
                try {
                    foreach ($ads->productCampaignDailyPerformance($lote, $inicio, $fim) as $d) {
                        $this->gravarDia($d);
                        $linhas++;
                    }
                } catch (Throwable $e) {
                    $falhas++;
                    $this->error("Shopee Ads: falha no desempenho de {$inicio->toDateString()} a {$fim->toDateString()}: {$e->getMessage()}");
                }
            }
        }

        $this->info("Shopee Ads: {$campanhas} campanha(s), {$linhas} dia(s) de desempenho de {$desde->toDateString()} a {$ate->toDateString()}.");

        return $falhas > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function gravarCampanha(array $c, Carbon $agora): void
    {
        $info = $c['common_info'] ?? [];
        $itens = array_map('strval', $info['item_id_list'] ?? []);
        $inicio = (int) ($info['campaign_duration']['start_time'] ?? 0);
        $fim = (int) ($info['campaign_duration']['end_time'] ?? 0);
        $meta = $c['auto_bidding_info']['roas_target'] ?? null;

        ShopeeAdCampanha::query()->updateOrCreate(
            ['campaign_id' => (int) $c['campaign_id']],
            [
                'ad_type' => $info['ad_type'] ?? null,
                'nome' => $info['ad_name'] ?? null,
                'status' => $info['campaign_status'] ?? null,
                'lance' => $info['bidding_method'] ?? null,
                'posicao' => $info['campaign_placement'] ?? null,
                'orcamento' => isset($info['campaign_budget']) ? (float) $info['campaign_budget'] : null,
                // 0 = sem meta configurada (lance manual ou automático sem alvo).
                'meta_roas' => $meta !== null && (float) $meta > 0 ? (float) $meta : null,
                'item_id' => $itens[0] ?? null,
                'itens' => $itens,
                'inicio' => $inicio > 0 ? Carbon::createFromTimestamp($inicio, config('app.timezone')) : null,
                'fim' => $fim > 0 ? Carbon::createFromTimestamp($fim, config('app.timezone')) : null,
                'sincronizado_em' => $agora,
            ],
        );
    }

    private function gravarDia(array $d): void
    {
        ShopeeAdCampanhaDia::query()->updateOrCreate(
            ['campaign_id' => $d['campaign_id'], 'data' => $d['date']],
            [
                'impressoes' => $d['impressions'],
                'cliques' => $d['clicks'],
                'gasto' => $d['spend'],
                'pedidos' => $d['broad_orders'],
                'unidades' => $d['broad_units'],
                'venda' => $d['broad_gmv'],
                'pedidos_diretos' => $d['direct_orders'],
                'venda_direta' => $d['direct_gmv'],
            ],
        );
    }
}
