<?php

namespace App\Modules\Marketplace\Support\Oportunidades;

use App\Models\ShopeeMaisVendidosCategoria;
use App\Models\ShopeeMaisVendidosColeta;
use App\Models\ShopeeMaisVendidosProduto;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Grava no banco uma mineração dos mais vendidos da Central do Vendedor da
 * Shopee (pedido do Lira 2026-10-12). Os dados vêm das mesmas chamadas que a
 * tela Dados › Assistente de Vendas › Produtos Mais Vendidos faz, rodadas na
 * aba logada da loja:
 *   - api/mydata/v4/seller-coach/top-products/category/?l1_cat_id&l2_cat_id
 *     (unidades vendidas por subcategoria) => `cats`
 *   - api/mydata/v3/seller-coach/top-products/item/?l1..l3_cat_id
 *     (30 produtos mais vendidos, sem os da própria loja) => `items`
 *
 * Formato do arquivo: {periodo_inicio, periodo_fim, coletado_em,
 * cats: [{l1_id, l1_name, l2_id, l2_name, l3: [{l3_cat_id, l3_cat_name,
 * l3_unit_sold}]}], items: [{itemid, shopid, image, title, price, min_price,
 * max_price, units_sold, rank, scope_l1, scope_l2, scope_l3}]}.
 */
class ImportarMaisVendidosShopee
{
    public function importar(array $dados): ShopeeMaisVendidosColeta
    {
        if (! isset($dados['cats'], $dados['items']) || ! is_array($dados['cats']) || ! is_array($dados['items'])) {
            throw new InvalidArgumentException('Arquivo sem as listas "cats" e "items".');
        }

        return DB::transaction(function () use ($dados) {
            $coleta = ShopeeMaisVendidosColeta::query()->create([
                'periodo_inicio' => $dados['periodo_inicio'] ?? null,
                'periodo_fim' => $dados['periodo_fim'] ?? null,
                'coletado_em' => isset($dados['coletado_em']) ? Carbon::parse($dados['coletado_em']) : now(),
            ]);

            $categorias = [];
            foreach ($dados['cats'] as $c) {
                foreach (array_values($c['l3'] ?? []) as $i => $l3) {
                    $categorias[] = [
                        'coleta_id' => $coleta->id,
                        'l1_id' => (int) $c['l1_id'], 'l1_nome' => (string) $c['l1_name'],
                        'l2_id' => (int) $c['l2_id'], 'l2_nome' => (string) $c['l2_name'],
                        'l3_id' => (int) $l3['l3_cat_id'], 'l3_nome' => (string) $l3['l3_cat_name'],
                        'posicao' => $i + 1,
                        'unidades_vendidas' => (int) ($l3['l3_unit_sold'] ?? 0),
                    ];
                }
            }

            $produtos = [];
            foreach ($dados['items'] as $p) {
                $produtos[] = [
                    'coleta_id' => $coleta->id,
                    'l1_id' => (int) $p['scope_l1'],
                    'l2_id' => (int) $p['scope_l2'],
                    'l3_id' => isset($p['scope_l3']) ? (int) $p['scope_l3'] : null,
                    'posicao' => (int) $p['rank'],
                    'item_id' => (int) $p['itemid'],
                    'shop_id' => (int) $p['shopid'],
                    'nome' => mb_substr((string) $p['title'], 0, 300),
                    'imagem' => isset($p['image']) ? mb_substr((string) $p['image'], 0, 120) : null,
                    'preco' => isset($p['price']) ? (float) $p['price'] : null,
                    'preco_min' => isset($p['min_price']) ? (float) $p['min_price'] : null,
                    'preco_max' => isset($p['max_price']) ? (float) $p['max_price'] : null,
                    'unidades_vendidas' => (int) ($p['units_sold'] ?? 0),
                ];
            }

            foreach (array_chunk($categorias, 500) as $lote) {
                ShopeeMaisVendidosCategoria::query()->insert($lote);
            }
            foreach (array_chunk($produtos, 500) as $lote) {
                ShopeeMaisVendidosProduto::query()->insert($lote);
            }

            $coleta->update(['total_categorias' => count($categorias), 'total_produtos' => count($produtos)]);

            return $coleta;
        });
    }
}
