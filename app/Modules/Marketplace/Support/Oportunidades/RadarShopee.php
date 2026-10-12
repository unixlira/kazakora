<?php

namespace App\Modules\Marketplace\Support\Oportunidades;

use App\Models\ShopeeRadarCategoria;
use App\Models\ShopeeRadarColeta;
use App\Models\ShopeeRadarProduto;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Radar da Shopee (pedido do Lira 2026-10-12): ranking "Produtos em alta"
 * da Central do Vendedor (datacenter/selling/top-products). Mostra, nas
 * categorias da loja, as subcategorias com mais unidades vendidas e os
 * produtos de TERCEIROS que mais vendem em cada uma.
 *
 * A Shopee não tem API pública disso e bloqueia robô fora do navegador
 * logado, então a leitura é feita no Chrome do Cora Box (mesmas chamadas que
 * a tela faz: seller-coach/top-products/category e .../item) e o JSON é
 * importado por `shopee:radar-importar`. Formato do arquivo:
 *
 *   {"coletado_em": "2026-10-12T18:00:00-03:00",
 *    "categorias": [resposta.result de top-products/category, ...],
 *    "produtos": [itens de top-products/item, ...]}
 */
class RadarShopee
{
    /** Imagem e anúncio públicos da Shopee a partir dos ids da API. */
    public const URL_IMAGEM = 'https://down-br.img.susercontent.com/file/';

    public const URL_PRODUTO = 'https://shopee.com.br/product/';

    public function importar(array $dados, string $origem = 'central-do-vendedor'): ShopeeRadarColeta
    {
        $categorias = [];
        foreach ($dados['categorias'] ?? [] as $grupo) {
            foreach ($grupo['l3_categories'] ?? [] as $i => $l3) {
                $categorias[(int) $l3['l3_cat_id']] = [
                    'l1_id' => (int) $grupo['l1_cat_id'],
                    'l1_nome' => (string) $grupo['l1_cat_name'],
                    'l2_id' => (int) $grupo['l2_cat_id'],
                    'l2_nome' => (string) $grupo['l2_cat_name'],
                    'l3_id' => (int) $l3['l3_cat_id'],
                    'l3_nome' => (string) $l3['l3_cat_name'],
                    'posicao' => $i + 1,
                    'unidades_vendidas' => (int) ($l3['l3_unit_sold'] ?? 0),
                ];
            }
        }

        // Posição = ordem em que a Shopee devolveu o item dentro da subcategoria.
        $produtos = [];
        $posicoes = [];
        foreach ($dados['produtos'] ?? [] as $item) {
            $l3 = (int) ($item['l3_cat_id'] ?? 0);
            $chave = $l3.':'.(int) $item['itemid'];
            if (isset($produtos[$chave])) {
                continue;
            }
            $posicoes[$l3] = ($posicoes[$l3] ?? 0) + 1;
            $produtos[$chave] = [
                'item_id' => (int) $item['itemid'],
                'shop_id' => (int) $item['shopid'],
                'l1_id' => (int) ($item['l1_cat_id'] ?? 0),
                'l2_id' => (int) ($item['l2_cat_id'] ?? 0),
                'l3_id' => $l3,
                'posicao' => $posicoes[$l3],
                'titulo' => mb_substr((string) $item['title'], 0, 500),
                'imagem' => isset($item['image']) ? mb_substr((string) $item['image'], 0, 120) : null,
                'preco' => isset($item['price']) ? (float) $item['price'] : null,
                'preco_min' => isset($item['min_price']) ? (float) $item['min_price'] : null,
                'preco_max' => isset($item['max_price']) ? (float) $item['max_price'] : null,
                'unidades_vendidas' => (int) ($item['units_sold'] ?? 0),
            ];
        }

        if ($categorias === [] && $produtos === []) {
            throw new InvalidArgumentException('Arquivo sem categorias e sem produtos.');
        }

        $coletadoEm = isset($dados['coletado_em'])
            ? CarbonImmutable::parse($dados['coletado_em'])->setTimezone(config('app.timezone'))
            : now();

        return DB::transaction(function () use ($categorias, $produtos, $coletadoEm, $origem) {
            $coleta = ShopeeRadarColeta::query()->create([
                'coletado_em' => $coletadoEm,
                'total_categorias' => count($categorias),
                'total_produtos' => count($produtos),
                'origem' => $origem,
            ]);
            foreach (array_chunk(array_values($categorias), 500) as $lote) {
                ShopeeRadarCategoria::query()->insert(array_map(fn ($c) => $c + ['coleta_id' => $coleta->id], $lote));
            }
            foreach (array_chunk(array_values($produtos), 500) as $lote) {
                ShopeeRadarProduto::query()->insert(array_map(fn ($p) => $p + ['coleta_id' => $coleta->id], $lote));
            }

            return $coleta;
        });
    }

    /** Última coleta para a tela: categorias agrupadas por nível 2 e produtos por vendas. */
    public function analisar(): array
    {
        $coleta = ShopeeRadarColeta::query()->latest('coletado_em')->latest('id')->first();
        if ($coleta === null) {
            return ['coletado_em' => null, 'grupos' => [], 'produtos' => []];
        }

        $nossaLoja = (string) MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)->value('seller_id');

        $categorias = $coleta->categorias()->orderBy('l1_id')->orderBy('l2_id')->orderBy('posicao')->get();
        $nomes = $categorias->keyBy('l3_id');
        $grupos = $categorias->groupBy('l2_id')->map(fn ($filhas) => [
            'l1_nome' => $filhas->first()->l1_nome,
            'l2_id' => $filhas->first()->l2_id,
            'l2_nome' => $filhas->first()->l2_nome,
            'unidades_vendidas' => (int) $filhas->sum('unidades_vendidas'),
            'subcategorias' => $filhas->map(fn ($c) => [
                'id' => $c->l3_id,
                'nome' => $c->l3_nome,
                'posicao' => $c->posicao,
                'unidades_vendidas' => $c->unidades_vendidas,
            ])->values()->all(),
        ])->sortByDesc('unidades_vendidas')->values()->all();

        $produtos = $coleta->produtos()->orderByDesc('unidades_vendidas')->get()->map(fn ($p) => [
            'id' => "{$p->l3_id}-{$p->item_id}",
            'nome' => $p->titulo,
            'imagem' => $p->imagem ? self::URL_IMAGEM.$p->imagem : null,
            'link' => self::URL_PRODUTO."{$p->shop_id}/{$p->item_id}",
            'posicao' => $p->posicao,
            'preco_min' => $p->preco_min ?? $p->preco,
            'preco_max' => $p->preco_max,
            'vendas' => $p->unidades_vendidas,
            'l2_id' => $p->l2_id,
            'l3_id' => $p->l3_id,
            'categoria' => $nomes[$p->l3_id]->l3_nome ?? null,
            'voce_vende' => $nossaLoja !== '' && (string) $p->shop_id === $nossaLoja,
        ])->all();

        return [
            'coletado_em' => $coleta->coletado_em->toIso8601String(),
            'grupos' => $grupos,
            'produtos' => $produtos,
        ];
    }
}
