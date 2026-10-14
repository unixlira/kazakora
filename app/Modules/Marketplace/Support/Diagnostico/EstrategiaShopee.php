<?php

namespace App\Modules\Marketplace\Support\Diagnostico;

use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Services\Shopee\ShopeeClient;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Publicidade › Shopee › Estratégia (tarefas 568/569, 2026-10-14): os
 * anúncios de ADS por produto que dão prejuízo, com a decisão do Cauê.
 * Só leitura (fase 1); o botão "Ação" vem depois.
 *
 * Regras da reunião com o Wallace e da skill caue-shopee-manager:
 *   - meta de ROAS = ROAS de empate + 2;
 *   - conversão do anúncio (pedidos ÷ cliques) mínima de 1,65%;
 *   - a conversão define o empate máximo que aguenta ADS:
 *     < 1% → empate < 4; 1 a 1,64% → < 5; 1,65 a 1,99% → ≤ 6; 2%+ → ≤ 8;
 *   - empate acima de 8 = margem baixa: o anúncio não resolve, é preço;
 *   - CTR abaixo de 2% (com 500+ impressões) = trocar a capa.
 *
 * PAUSAR: gastou R$ 20+ sem venda, ou está abaixo do empate com conversão
 * abaixo de 1,65%, fora da faixa e ROAS real abaixo de 80% do empate. Quem
 * converte bem (ou está perto do empate) fica com META = empate + 2, e PREÇO
 * se o empate passar de 8. Com os dados de 15/09 a 14/10 isso dá o mesmo
 * plano da tarefa 566 (Documents/2026-10-14-plano-shopee-ads-7-campanhas.md).
 *
 * Empate vem da margem real do KazaKora (EconomiaDosAnuncios), não do
 * Seller Bump. Resultado estimado = vendas do ADS × margem − gasto.
 */
class EstrategiaShopee
{
    public const CONVERSAO_MINIMA = 1.65;

    public const META_ACIMA_DO_EMPATE = 2.0;

    /** ROAS real abaixo desta fração do empate é prejuízo grande: pausa. */
    public const LONGE_DO_EMPATE = 0.8;

    /** Abaixo disso o gasto é pequeno demais para julgar a campanha. */
    public const GASTO_MINIMO = 10.0;

    public function __construct(
        private readonly ShopeeClient $shopee,
        private readonly EconomiaDosAnuncios $economia,
        private readonly DiagnosticoShopee $diagnostico,
    ) {}

    public function gerar(int $dias = 30): array
    {
        $desde = Carbon::today()->subDays($dias - 1);
        $ads = array_filter($this->diagnostico->adsPorItem($desde), fn ($a) => $a['gasto'] > 0);
        $ids = array_map('strval', array_keys($ads));

        $vendas = $this->economia->porAnuncio('shopee', $desde);
        $taxaMedia = $this->economia->taxaMedia('shopee', $desde);
        $anuncios = ProductChannelListing::query()
            ->where('channel', 'shopee')
            ->whereIn('external_id', $ids)
            ->get(['product_id', 'external_id']);
        $custos = Product::query()->whereIn('id', $anuncios->pluck('product_id'))->pluck('cost_price', 'id');
        $porItem = $anuncios->groupBy(fn ($l) => (string) $l->external_id);
        $base = $this->itens($ids);

        $linhas = [];
        foreach ($ads as $id => $ad) {
            $id = (string) $id;
            $b = $base[$id] ?? [];
            $preco = (float) ($b['price_info'][0]['current_price'] ?? 0);
            $listagens = $porItem->get($id, collect());
            $custo = $listagens->map(fn ($l) => $custos[$l->product_id] ?? null)->filter()->avg();
            $eco = $vendas[$id] ?? $this->economia->estimar($preco, $custo !== null ? (float) $custo : null, $taxaMedia);
            $linhas[] = $this->linha($id, $b, $ad, $eco, $listagens->pluck('product_id')->first());
        }

        usort($linhas, fn ($a, $b) => $a['resultado'] <=> $b['resultado']);
        $gasto = array_sum(array_column($ads, 'gasto'));
        $gmv = array_sum(array_column($ads, 'gmv'));
        $cliques = array_sum(array_column($ads, 'cliques'));
        $prejuizo = array_values(array_filter($linhas, fn ($l) => $l['grupo'] === 'prejuizo'));

        return [
            'gerado_em' => now()->toIso8601String(),
            'dias' => $dias,
            'desde' => $desde->toDateString(),
            'ate' => Carbon::today()->toDateString(),
            'kpis' => [
                'gasto' => round($gasto, 2),
                'vendas' => round($gmv, 2),
                'roas' => $gasto > 0 ? round($gmv / $gasto, 2) : null,
                'conversao' => $cliques > 0 ? round(array_sum(array_column($ads, 'pedidos')) / $cliques * 100, 2) : null,
                'campanhas' => count($ads),
                'com_prejuizo' => count($prejuizo),
                'prejuizo' => round(array_sum(array_map(fn ($l) => min(0, $l['resultado']), $prejuizo)), 2),
            ],
            'linhas' => $linhas,
        ];
    }

    private function linha(string $id, array $b, array $ad, array $eco, ?int $produtoId): array
    {
        $empate = $eco['roas_empate'] ?? null;
        $conversao = $ad['conversao'] ?? null;
        $margem = (float) ($eco['margem'] ?? 0) / 100;
        $semMargem = $empate === null;
        $abaixoDoEmpate = $semMargem || $ad['roas'] < $empate;
        $semVenda = $ad['gasto'] >= 20 && $ad['pedidos'] === 0;
        $foraDaFaixa = $semMargem || ! self::margemAguenta($conversao, $empate);
        $converteMal = $conversao === null || $conversao < self::CONVERSAO_MINIMA;
        $longe = $semMargem || $ad['roas'] < $empate * self::LONGE_DO_EMPATE;
        $metaSugerida = $empate !== null ? round($empate + self::META_ACIMA_DO_EMPATE, 1) : null;

        $decisoes = [];
        if ($semVenda || ($abaixoDoEmpate && $converteMal && $foraDaFaixa && $longe)) {
            $decisoes[] = 'PAUSAR';
        } elseif ($abaixoDoEmpate || ($ad['ativo'] && $metaSugerida && $ad['meta'] > 0 && $ad['meta'] < $metaSugerida)) {
            $decisoes[] = 'META';
        }
        if ($semMargem || $empate > 8) {
            $decisoes[] = 'PRECO';
        }
        if ($ad['impressoes'] >= 500 && $ad['ctr'] < 2) {
            $decisoes[] = 'CAPA';
        }

        $relevante = $ad['gasto'] >= self::GASTO_MINIMO;
        $grupo = match (true) {
            $relevante && ($semVenda || $abaixoDoEmpate) => 'prejuizo',
            $relevante && $conversao !== null && $conversao < self::CONVERSAO_MINIMA => 'conversao_baixa',
            default => 'ok',
        };

        return [
            'item_id' => $id,
            'produto_id' => $produtoId,
            'nome' => (string) ($b['item_name'] ?? "Item {$id}"),
            'imagem' => $b['image']['image_url_list'][0] ?? null,
            'link' => "https://seller.shopee.com.br/portal/product/{$id}",
            'preco' => (float) ($b['price_info'][0]['current_price'] ?? 0),
            'campanha_id' => $ad['campanha_id'] ?? null,
            'status' => $ad['status'] ?? '',
            'ativo' => (bool) $ad['ativo'],
            'gasto' => round($ad['gasto'], 2),
            'vendas' => round($ad['gmv'], 2),
            'pedidos' => $ad['pedidos'],
            'cliques' => $ad['cliques'],
            'impressoes' => $ad['impressoes'],
            'roas' => $ad['roas'],
            'roas_empate' => $empate,
            'meta_sugerida' => $metaSugerida,
            'meta_atual' => $ad['meta'] > 0 ? $ad['meta'] : null,
            'conversao' => $conversao,
            'ctr' => $ad['ctr'],
            'margem' => $eco['margem'] ?? null,
            'sem_custo' => (bool) ($eco['sem_custo'] ?? false),
            'estimado' => (bool) ($eco['estimado'] ?? false),
            'resultado' => round($ad['gmv'] * $margem - $ad['gasto'], 2),
            'decisoes' => $decisoes,
            'grupo' => $grupo,
        ];
    }

    /** Régua do Cauê: a conversão do anúncio diz até que empate o ADS se paga. */
    public static function margemAguenta(?float $conversao, ?float $empate): bool
    {
        if ($empate === null || $conversao === null) {
            return false;
        }

        return match (true) {
            $conversao < 1 => $empate < 4,
            $conversao < self::CONVERSAO_MINIMA => $empate < 5,
            $conversao < 2 => $empate <= 6,
            default => $empate <= 8,
        };
    }

    /** @return array<string, array> */
    private function itens(array $ids): array
    {
        $itens = [];
        foreach (array_chunk($ids, 50) as $lote) {
            try {
                $lista = $this->shopee->get('/api/v2/product/get_item_base_info', ['item_id_list' => implode(',', $lote)])['response']['item_list'] ?? [];
                foreach ($lista as $i) {
                    $itens[(string) $i['item_id']] = $i;
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $itens;
    }
}
