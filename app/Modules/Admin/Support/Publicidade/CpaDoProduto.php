<?php

namespace App\Modules\Admin\Support\Publicidade;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductSlugAntigo;
use App\Modules\Checkout\Models\Order;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use App\Modules\Marketplace\Support\ContributionMargin;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CPA alvo e CPA máximo por produto (Leitura das campanhas, Lira 25/11/2026).
 *
 * CPA máximo = o "0 a 0": quanto dá para pagar de anúncio por venda sem
 * perder dinheiro.
 *
 *   preço médio pago (receita da venda, já sem cupom/desconto)
 *   − custo do produto (cost_price)
 *   − frete médio real (etiquetas que a loja pagou)
 *   − imposto do Simples (LOJA_SIMPLES_ALIQUOTA, % do preço)
 *   − insumos por pedido (LOJA_INSUMOS_POR_PEDIDO)
 *   − taxa do pagamento (cartão 4,98%, o pior caso)
 *   = CPA máximo;   CPA alvo = metade do máximo.
 *
 * As peças vêm de ContributionMargin::porItem() (mesma receita, custo e
 * frete de todas as telas de lucro), só das vendas do site do produto nos
 * últimos N dias (config leitura_meta.janela_vendas_dias). Sem venda na
 * janela: preço de cadastro (final_price), cost_price e o frete padrão.
 * Frete médio = só dos pedidos que já têm etiqueta paga (pedido recente sem
 * etiqueta não puxa a média para zero).
 *
 * Alvo/máximo digitados na tela (leitura_meta_cpa) valem no lugar do calculado.
 */
class CpaDoProduto
{
    /** @var array<string, array<string, mixed>|null> */
    private array $memo = [];

    /** @var array<string, \Illuminate\Support\Collection<int, array<string, mixed>>> linhas de porItem por canal */
    private array $linhas = [];

    /** Produto do link do anúncio (kazakora.com/produtos/{slug} ou ferrakora.com/produto/{slug}). */
    public static function produtoDoLink(?string $link): ?int
    {
        if (! is_string($link) || ! preg_match('#^https?://(?:www\.)?([^/]+)/(produtos?)/([^/?\#]+)#i', $link, $m)) {
            return null;
        }
        $slug = urldecode($m[3]);

        if (str_contains(strtolower($m[1]), 'ferrakora')) {
            $id = FerraKoraProduto::query()->where('slug', $slug)->value('product_id');
            if ($id) {
                return (int) $id;
            }
        }

        $id = Product::query()->where('slug', $slug)->value('id')
            ?? ProductSlugAntigo::query()->where('slug', $slug)->value('product_id');

        return $id ? (int) $id : null;
    }

    /**
     * @return array{product_id: int, nome: string, pedidos: int, preco: float, custo: float, frete: float, imposto: float, insumos: float, taxa: float, imposto_pct: float, taxa_pct: float, cpa_maximo: float, cpa_alvo: float, calculado_maximo: float, calculado_alvo: float, manual: bool, fonte: string}|null
     */
    public function doProduto(int $productId, string $loja = CampanhasMetaPorLoja::KAZAKORA): ?array
    {
        $chave = $productId.'|'.$loja;
        if (array_key_exists($chave, $this->memo)) {
            return $this->memo[$chave];
        }

        $produto = Product::query()->find($productId);
        if (! $produto) {
            return $this->memo[$chave] = null;
        }

        $impostoPct = ContributionMargin::aliquotaSimples();
        $taxaPct = (float) config('leitura_meta.taxa_pagamento', 4.98);
        $insumos = ContributionMargin::insumosPorPedido();

        $vendas = $this->vendas($productId, $loja);
        $preco = $vendas['pedidos'] > 0 ? $vendas['preco'] : (float) $produto->final_price;
        $custo = $vendas['pedidos'] > 0 && $vendas['custo'] > 0 ? $vendas['custo'] : (float) $produto->cost_price;
        $frete = $vendas['frete'] ?? (float) config('leitura_meta.frete_padrao', 25);

        $conta = self::conta($preco, $custo, $frete, $impostoPct, $taxaPct, $insumos);
        $manual = DB::table('leitura_meta_cpa')->where('product_id', $productId)->first();
        $maximo = $manual?->cpa_maximo !== null ? (float) $manual->cpa_maximo : $conta['cpa_maximo'];
        $alvo = $manual?->cpa_alvo !== null ? (float) $manual->cpa_alvo
            : ($manual?->cpa_maximo !== null ? round($maximo / 2, 2) : $conta['cpa_alvo']);

        return $this->memo[$chave] = array_merge([
            'product_id' => $productId,
            'nome' => (string) $produto->name,
            'pedidos' => $vendas['pedidos'],
            'preco' => round($preco, 2),
            'custo' => round($custo, 2),
            'frete' => round($frete, 2),
            'imposto_pct' => $impostoPct,
            'taxa_pct' => $taxaPct,
            'insumos' => round($insumos, 2),
        ], $conta, [
            'calculado_maximo' => $conta['cpa_maximo'],
            'calculado_alvo' => $conta['cpa_alvo'],
            'cpa_maximo' => round($maximo, 2),
            'cpa_alvo' => round($alvo, 2),
            'manual' => $manual !== null && ($manual->cpa_alvo !== null || $manual->cpa_maximo !== null),
            'fonte' => $vendas['pedidos'] > 0
                ? "média de {$vendas['pedidos']} ".($vendas['pedidos'] === 1 ? 'venda' : 'vendas').' do site em '.(int) config('leitura_meta.janela_vendas_dias', 90).' dias'
                : 'sem venda recente: preço de cadastro e frete padrão',
        ]);
    }

    /**
     * A conta do 0 a 0 (pura, para testar e para a tooltip).
     *
     * @return array{imposto: float, taxa: float, cpa_maximo: float, cpa_alvo: float}
     */
    public static function conta(float $preco, float $custo, float $frete, float $impostoPct, float $taxaPct, float $insumos): array
    {
        $imposto = $preco * $impostoPct / 100;
        $taxa = $preco * $taxaPct / 100;
        $maximo = round($preco - $custo - $frete - $imposto - $insumos - $taxa, 2);

        return [
            'imposto' => round($imposto, 2),
            'taxa' => round($taxa, 2),
            'cpa_maximo' => $maximo,
            'cpa_alvo' => round($maximo / 2, 2),
        ];
    }

    /**
     * Médias por pedido das vendas do produto no site (ContributionMargin::porItem).
     *
     * @return array{pedidos: int, preco: float, custo: float, frete: ?float}
     */
    private function vendas(int $productId, string $loja): array
    {
        $ate = now()->addDay()->startOfDay();
        $desde = Carbon::parse($ate)->subDays(max(1, (int) config('leitura_meta.janela_vendas_dias', 90)));
        $canal = $loja === CampanhasMetaPorLoja::FERRAKORA ? Order::SITE_FERRAKORA : Order::ORIGIN_STORE;

        $this->linhas[$canal] ??= collect(app(ContributionMargin::class)->porItem($desde, $ate, $canal)['linhas']);
        $linhas = $this->linhas[$canal]->where('product_id', $productId);
        $porPedido = $linhas->groupBy('order_id')->map(fn ($itens) => [
            'receita' => (float) $itens->sum('receita'),
            'custo' => (float) $itens->sum('custo_produtos'),
            'frete' => (float) $itens->sum('frete'),
        ]);
        $pedidos = $porPedido->count();
        $comFrete = $porPedido->filter(fn ($p) => $p['frete'] > 0);

        return [
            'pedidos' => $pedidos,
            'preco' => $pedidos ? $porPedido->avg('receita') : 0.0,
            'custo' => $pedidos ? $porPedido->avg('custo') : 0.0,
            'frete' => $comFrete->isNotEmpty() ? (float) $comFrete->avg('frete') : null,
        ];
    }

    /** Grava (ou limpa, com os dois vazios) o alvo/máximo digitado na tela. */
    public static function sobrescrever(int $productId, ?float $alvo, ?float $maximo): void
    {
        if ($alvo === null && $maximo === null) {
            DB::table('leitura_meta_cpa')->where('product_id', $productId)->delete();

            return;
        }

        DB::table('leitura_meta_cpa')->updateOrInsert(['product_id' => $productId], [
            'cpa_alvo' => $alvo,
            'cpa_maximo' => $maximo,
            'updated_at' => now(),
            'created_at' => now(),
        ]);
    }
}
