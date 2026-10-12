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

    /** Produtos por página na tela. */
    public const POR_PAGINA = 20;

    /** Categoria de nível 1 que a tela abre: Casa e Decoração (o forte da loja). */
    public const L1_PADRAO = 100636;

    /**
     * Última coleta para a tela, filtrada e paginada no servidor: a mineração
     * completa tem dezenas de milhares de produtos e não cabe no navegador
     * (2026-10-12).
     *
     * $filtros: l1 (id ou 0 = todas), categoria ('g:<l2>' ou 's:<l3>'),
     * busca (palavra no título) e pagina.
     */
    public function analisar(array $filtros = []): array
    {
        $coleta = ShopeeRadarColeta::query()->latest('coletado_em')->latest('id')->first();
        if ($coleta === null) {
            return ['coletado_em' => null, 'filtros' => null, 'niveis1' => [], 'grupos' => [], 'totais' => null, 'produtos' => [], 'paginacao' => null];
        }

        $nossaLoja = (string) MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)->value('seller_id');

        $categorias = $coleta->categorias()->orderBy('posicao')->get();
        $niveis1 = $categorias->groupBy('l1_id')->map(fn ($filhas) => [
            'id' => (int) $filhas->first()->l1_id,
            'nome' => $filhas->first()->l1_nome,
            'unidades_vendidas' => (int) $filhas->sum('unidades_vendidas'),
        ])->sortByDesc('unidades_vendidas')->values();

        $l1 = array_key_exists('l1', $filtros) ? (int) $filtros['l1'] : self::L1_PADRAO;
        if ($l1 && ! $niveis1->contains('id', $l1)) {
            $l1 = 0;
        }
        $categoria = preg_match('/^[gs]:\d{1,12}$/', (string) ($filtros['categoria'] ?? '')) ? (string) $filtros['categoria'] : '';
        $busca = mb_substr(trim((string) ($filtros['busca'] ?? '')), 0, 80);
        // Categoria escolhida leva os cartões para o nível 1 dela.
        if ($categoria !== '') {
            $dona = $categorias->firstWhere($categoria[0] === 'g' ? 'l2_id' : 'l3_id', (int) substr($categoria, 2));
            $l1 = $dona ? (int) $dona->l1_id : $l1;
            $categoria = $dona ? $categoria : '';
        }

        // Cartões: com nível 1 escolhido, cada nível 2 com as subcategorias;
        // em "todas", cada nível 1 com os níveis 2 que mais vendem.
        $doNivel = $l1 ? $categorias->where('l1_id', $l1) : $categorias;
        $grupos = $l1
            ? $doNivel->groupBy('l2_id')->map(fn ($filhas) => [
                'id' => 'g:'.$filhas->first()->l2_id,
                'sobre' => $filhas->first()->l1_nome,
                'nome' => $filhas->first()->l2_nome,
                'unidades_vendidas' => (int) $filhas->sum('unidades_vendidas'),
                'itens' => $filhas->sortBy('posicao')->map(fn ($c) => [
                    'id' => 's:'.$c->l3_id, 'nome' => $c->l3_nome, 'posicao' => $c->posicao, 'unidades_vendidas' => $c->unidades_vendidas,
                ])->values()->all(),
            ])
            : $doNivel->groupBy('l1_id')->map(fn ($filhas) => [
                'id' => 'l1:'.$filhas->first()->l1_id,
                'sobre' => 'Categoria',
                'nome' => $filhas->first()->l1_nome,
                'unidades_vendidas' => (int) $filhas->sum('unidades_vendidas'),
                'itens' => $filhas->groupBy('l2_id')->map(fn ($l2) => [
                    'id' => 'g:'.$l2->first()->l2_id, 'nome' => $l2->first()->l2_nome, 'unidades_vendidas' => (int) $l2->sum('unidades_vendidas'),
                ])->sortByDesc('unidades_vendidas')->values()->take(8)
                    ->map(fn ($item, $i) => $item + ['posicao' => $i + 1])->all(),
            ]);
        $grupos = $grupos->sortByDesc('unidades_vendidas')->values()->all();

        $query = $coleta->produtos();
        if ($categoria !== '') {
            $query->where($categoria[0] === 'g' ? 'l2_id' : 'l3_id', (int) substr($categoria, 2));
        } elseif ($l1) {
            $query->where('l1_id', $l1);
        }
        if ($busca !== '') {
            $query->where('titulo', 'like', '%'.addcslashes($busca, '%_\\').'%');
        }
        $total = (clone $query)->count();
        $paginas = max(1, (int) ceil($total / self::POR_PAGINA));
        $pagina = min(max(1, (int) ($filtros['pagina'] ?? 1)), $paginas);

        $nomes = $categorias->keyBy('l3_id');
        $produtos = $query
            ->when(str_starts_with($categoria, 's:'), fn ($q) => $q->orderBy('posicao'), fn ($q) => $q->orderByDesc('unidades_vendidas'))
            ->orderBy('id')
            ->forPage($pagina, self::POR_PAGINA)
            ->get()
            ->map(fn ($p) => [
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
            'filtros' => ['l1' => $l1, 'categoria' => $categoria, 'busca' => $busca],
            'niveis1' => $niveis1->all(),
            'grupos' => $grupos,
            'totais' => [
                'niveis2' => $categorias->unique('l2_id')->count(),
                'subcategorias' => $categorias->count(),
                'produtos' => $coleta->total_produtos,
                'voce_vende' => $nossaLoja === '' ? 0 : $coleta->produtos()->where('shop_id', $nossaLoja)->count(),
            ],
            'produtos' => $produtos,
            'paginacao' => ['pagina' => $pagina, 'paginas' => $paginas, 'total' => $total, 'por_pagina' => self::POR_PAGINA],
        ];
    }
}
