<?php

namespace App\Services\Meta;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\MetaAdsCampanha;
use App\Modules\Marketplace\Models\MetaAdsVideo;
use App\Modules\Marketplace\Support\ContributionMargin;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * "ROAS mínimo para não perder dinheiro" de cada campanha da Meta
 * (2026-10-21): 1 ÷ margem antes do ADS, sempre pela conta oficial
 * (ContributionMargin::porItem, cujas linhas já são receita − custo dos
 * produtos − taxas − frete, sem o ADS). Canal do site (kazakora.com), que é
 * para onde os anúncios da Meta levam. Ordem e limites em
 * config/meta_ads_diagnostico.php:
 *   1. produtos da campanha (produto da campanha e de cada anúncio);
 *   2. o site inteiro;
 *   3. valor fixo da configuração.
 * Guardado 1 hora: a margem de 30 dias não muda de um clique para outro.
 */
class MetaRoasMinimo
{
    public const ORIGEM_PRODUTOS = 'produtos';

    public const ORIGEM_LOJA = 'loja';

    public const ORIGEM_CONFIG = 'config';

    public const ORIGENS = [
        self::ORIGEM_PRODUTOS => 'margem dos produtos da campanha no site',
        self::ORIGEM_LOJA => 'margem do site',
        self::ORIGEM_CONFIG => 'valor padrão da configuração (o site ainda tem poucas vendas para calcular)',
    ];

    public function __construct(private ContributionMargin $margem) {}

    /**
     * @param  list<string>  $metaCampaignIds
     * @return array{padrao: array{valor: float, origem: string, margem: ?float}, campanhas: array<string, array{valor: float, origem: string, margem: ?float}>}
     */
    public function metas(array $metaCampaignIds): array
    {
        $dias = (int) config('meta_ads_diagnostico.roas_minimo_dias', 30);
        sort($metaCampaignIds);

        return Cache::remember('meta-ads:roas-minimo:'.md5($dias.'|'.implode(',', $metaCampaignIds)), now()->addHour(), function () use ($metaCampaignIds, $dias) {
            $ate = Carbon::now('America/Sao_Paulo')->startOfDay()->addDay();
            $linhas = collect($this->margem->porItem($ate->copy()->subDays($dias), $ate, Order::ORIGIN_STORE)['linhas']);

            $padrao = $this->daMargem($linhas, self::ORIGEM_LOJA) ?? $this->fixo();

            $produtos = MetaAdsCampanha::query()
                ->whereIn('meta_campaign_id', $metaCampaignIds)
                ->with('videos:id,campanha_id,produto_id')
                ->get(['id', 'meta_campaign_id', 'produto_id'])
                ->mapWithKeys(fn (MetaAdsCampanha $c) => [(string) $c->meta_campaign_id => collect([$c->produto_id])
                    ->merge($c->videos->map(fn (MetaAdsVideo $v) => $v->produto_id))
                    ->filter()->unique()->values()->all()]);

            $campanhas = [];
            foreach ($metaCampaignIds as $id) {
                $ids = $produtos->get((string) $id, []);
                $campanhas[(string) $id] = ($ids ? $this->daMargem($linhas->whereIn('product_id', $ids), self::ORIGEM_PRODUTOS) : null) ?? $padrao;
            }

            return ['padrao' => $padrao, 'campanhas' => $campanhas];
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $linhas
     * @return array{valor: float, origem: string, margem: float}|null
     */
    private function daMargem($linhas, string $origem): ?array
    {
        $pedidos = $linhas->pluck('order_id')->unique()->count();
        $receita = (float) $linhas->sum('receita');

        if ($pedidos < (int) config('meta_ads_diagnostico.pedidos_minimos', 5) || $receita <= 0) {
            return null;
        }

        $margem = (float) $linhas->sum('margem') / $receita;
        if ($margem < (float) config('meta_ads_diagnostico.margem_minima_confiavel', 0.05)) {
            return null;
        }

        return ['valor' => round(1 / $margem, 2), 'origem' => $origem, 'margem' => round($margem, 4)];
    }

    /** @return array{valor: float, origem: string, margem: null} */
    private function fixo(): array
    {
        return ['valor' => (float) config('meta_ads_diagnostico.roas_minimo_padrao', 3.0), 'origem' => self::ORIGEM_CONFIG, 'margem' => null];
    }
}
