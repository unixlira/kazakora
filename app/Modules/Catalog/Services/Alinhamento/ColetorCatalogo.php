<?php

namespace App\Modules\Catalog\Services\Alinhamento;

use App\Modules\Catalog\Models\Product;
use App\Services\Bling\BlingClient;
use App\Services\Shopee\ShopeeClient;
use Closure;
use Throwable;

/**
 * Alinhamento do catálogo (pedido 2026-10-11), etapa 1: retrato SÓ DE
 * LEITURA de Kazakora, Shopee (com variações e tax_info) e Bling (com
 * tributação). As etapas seguintes trabalham em cima deste retrato, sem
 * bater de novo nas APIs.
 */
class ColetorCatalogo
{
    /** Bling: 3 req/s para a conta inteira, dividida com nota e etiqueta. */
    private const PAUSA_BLING_US = 400_000;

    public function __construct(
        private readonly ShopeeClient $shopee,
        private readonly BlingClient $bling,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function kazakora(): array
    {
        return Product::query()
            ->with(['fiscalData', 'channelListings', 'parent:id,name,sku', 'category:id,name'])
            ->withCount('children')
            ->orderBy('id')
            ->get()
            ->map(fn (Product $p) => [
                'id' => $p->id,
                'sku' => $p->sku,
                'nome' => $p->name,
                'pai' => $p->parent ? ['id' => $p->parent->id, 'nome' => $p->parent->name, 'sku' => $p->parent->sku] : null,
                'tem_variacoes' => $p->children_count > 0,
                'variacao' => $p->variation,
                'marca' => $p->brand,
                'categoria' => $p->category?->name,
                'ativo' => (bool) $p->is_active,
                'preco' => (float) $p->price,
                'descricao' => mb_substr(strip_tags((string) $p->description), 0, 600),
                'fiscal' => $p->fiscalData?->only([
                    'ncm', 'cest', 'origem', 'gtin', 'unidade_tributavel', 'cfop', 'cfop_outros_estados',
                    'peso_bruto', 'peso_liquido', 'altura_cm', 'largura_cm', 'profundidade_cm',
                ]),
                'anuncios' => $p->channelListings->map(fn ($l) => [
                    'canal' => $l->channel, 'external_id' => $l->external_id, 'external_model_id' => $l->external_model_id, 'status' => $l->status,
                ])->values()->all(),
            ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function shopee(?Closure $progresso = null): array
    {
        $ids = [];
        foreach (['NORMAL', 'UNLIST'] as $status) {
            $offset = 0;
            do {
                $pagina = $this->shopee->get('/api/v2/product/get_item_list', ['offset' => $offset, 'page_size' => 100, 'item_status' => $status]);
                foreach ($pagina['response']['item'] ?? [] as $item) {
                    $ids[(int) $item['item_id']] = $status;
                }
                $offset = (int) ($pagina['response']['next_offset'] ?? $offset + 100);
            } while ($pagina['response']['has_next_page'] ?? false);
        }

        $itens = [];
        foreach (array_chunk(array_keys($ids), 50) as $lote) {
            $base = $this->shopee->get('/api/v2/product/get_item_base_info', [
                'item_id_list' => implode(',', $lote),
                'need_tax_info' => 'true',
            ]);

            foreach ($base['response']['item_list'] ?? [] as $item) {
                $id = (int) $item['item_id'];
                $modelos = [];
                if ($item['has_model'] ?? false) {
                    try {
                        $lista = $this->shopee->get('/api/v2/product/get_model_list', ['item_id' => $id])['response'] ?? [];
                        $opcoes = $lista['tier_variation'][0]['option_list'] ?? [];
                        foreach ($lista['model'] ?? [] as $m) {
                            $modelos[] = [
                                'model_id' => (string) $m['model_id'],
                                'sku' => (string) ($m['model_sku'] ?? ''),
                                'opcao' => $opcoes[$m['tier_index'][0] ?? -1]['option'] ?? null,
                                'preco' => $m['price_info'][0]['current_price'] ?? null,
                            ];
                        }
                    } catch (Throwable $e) {
                        $modelos = [['erro' => $e->getMessage()]];
                    }
                }

                $itens[] = [
                    'item_id' => (string) $id,
                    'status' => $ids[$id],
                    'nome' => (string) ($item['item_name'] ?? ''),
                    'sku' => (string) ($item['item_sku'] ?? ''),
                    'categoria_id' => $item['category_id'] ?? null,
                    'marca' => $item['brand']['original_brand_name'] ?? null,
                    'peso' => $item['weight'] ?? null,
                    'dimensao' => $item['dimension'] ?? null,
                    'tax_info' => $item['tax_info'] ?? null,
                    'modelos' => $modelos,
                ];
                $progresso && $progresso('shopee', count($itens));
            }
        }

        return $itens;
    }

    /** @return array<int, array<string, mixed>> */
    public function bling(?Closure $progresso = null): array
    {
        $ids = [];
        $pagina = 1;
        do {
            $lista = $this->bling->get('produtos', ['pagina' => $pagina, 'limite' => 100])['data'] ?? [];
            foreach ($lista as $p) {
                $ids[] = (int) $p['id'];
            }
            $pagina++;
            usleep(self::PAUSA_BLING_US);
        } while (count($lista) === 100);

        $produtos = [];
        foreach ($ids as $id) {
            try {
                $d = $this->bling->get("produtos/{$id}")['data'] ?? [];
            } catch (Throwable $e) {
                $produtos[] = ['id' => $id, 'erro' => $e->getMessage()];

                continue;
            }
            $produtos[] = [
                'id' => $id,
                'codigo' => (string) ($d['codigo'] ?? ''),
                'nome' => (string) ($d['nome'] ?? ''),
                'situacao' => $d['situacao'] ?? null,
                'formato' => $d['formato'] ?? null,
                'tipo' => $d['tipo'] ?? null,
                'gtin' => $d['gtin'] ?? null,
                'unidade' => $d['unidade'] ?? null,
                'marca' => $d['marca'] ?? null,
                'peso_liquido' => $d['pesoLiquido'] ?? null,
                'peso_bruto' => $d['pesoBruto'] ?? null,
                'dimensoes' => $d['dimensoes'] ?? null,
                'tributacao' => isset($d['tributacao']) ? array_intersect_key($d['tributacao'], array_flip(['origem', 'ncm', 'cest', 'tipoItem', 'percentualTributos', 'grupoProduto'])) : null,
                'pai_id' => $d['variacao']['produtoPai']['id'] ?? null,
                'variacoes' => collect($d['variacoes'] ?? [])->map(fn ($v) => ['id' => $v['id'] ?? null, 'codigo' => $v['codigo'] ?? null, 'nome' => $v['nome'] ?? null])->all(),
            ];
            $progresso && $progresso('bling', count($produtos));
            usleep(self::PAUSA_BLING_US);
        }

        return $produtos;
    }
}
