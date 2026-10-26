<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Models\AfiliadoProduto;
use App\Modules\Marketplace\Support\AfiliadosShopee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Afiliados › Shopee (pedido do Lira em 2026-10-26): escolher quais
 * produtos vão ser clonados como anúncio de afiliado na Shopee, com o preço
 * do clone (custo × 1,5) e o SKU com "-AF". Só escolhe e guarda; nada é
 * criado na Shopee daqui.
 */
class AfiliadosShopeeController extends Controller
{
    public function index(Request $request, AfiliadosShopee $afiliados): Response
    {
        $filtros = $request->validate([
            'grupo' => ['nullable', Rule::in([...array_keys(AfiliadosShopee::GRUPOS), 'escolhidos'])],
            'q' => ['nullable', 'string', 'max:100'],
        ]);
        $grupo = $filtros['grupo'] ?? null;
        $q = trim((string) ($filtros['q'] ?? ''));

        $escolhidos = AfiliadoProduto::query()->where('canal', AfiliadosShopee::CANAL)->get()->keyBy('product_id');
        $semShopee = $afiliados->semShopee();

        // Busca por nome/SKU olha o catálogo inteiro (para escolher fora dos grupos).
        $query = match (true) {
            $q !== '' => Product::query()->whereNull('parent_product_id')->where(fn (Builder $w) => $w
                ->where('name', 'like', '%'.addcslashes($q, '\\%_').'%')
                ->orWhere('sku', 'like', '%'.addcslashes($q, '\\%_').'%')),
            $grupo === 'escolhidos' => Product::query()->whereIn('id', $escolhidos->keys()),
            default => $afiliados->query($grupo),
        };

        $produtos = $query->with(['category:id,name', 'channelListings' => fn ($l) => $l->where('channel', AfiliadosShopee::CANAL)])
            ->orderBy('name')->limit(300)->get()
            ->map(function (Product $p) use ($afiliados, $escolhidos, $semShopee) {
                $custo = $p->cost_price !== null ? (float) $p->cost_price : null;
                $preco = AfiliadosShopee::preco($custo);
                $escolha = $escolhidos->get($p->id);
                $shopee = $p->channelListings->first()?->external_id;

                return [
                    'id' => $p->id,
                    'sku' => $p->sku,
                    'sku_afiliado' => AfiliadosShopee::skuAfiliado($p->sku),
                    'nome' => $p->name,
                    'grupo' => $afiliados->grupoDe($p, $semShopee),
                    'ativo' => (bool) $p->is_active,
                    'custo' => $custo,
                    'preco_atual' => $p->final_price,
                    'preco_afiliado' => $preco,
                    'comissao' => $preco !== null ? round($preco * AfiliadosShopee::COMISSAO, 2) : null,
                    'shopee_item_id' => $shopee,
                    'escolhido' => $escolha !== null,
                    'escolhido_preco' => $escolha ? (float) $escolha->preco : null,
                ];
            })->values();

        $contagem = [];
        foreach (array_keys(AfiliadosShopee::GRUPOS) as $g) {
            $contagem[$g] = $afiliados->query($g)->count();
        }

        return Inertia::render('Admin/Afiliados/Shopee', [
            'produtos' => $produtos,
            'grupos' => AfiliadosShopee::GRUPOS,
            'contagem' => $contagem,
            'filtros' => ['grupo' => $grupo ?? '', 'q' => $q],
            'totais' => [
                'escolhidos' => $escolhidos->count(),
                'sugeridos' => $afiliados->query()->count(),
            ],
            'regra' => ['markup' => AfiliadosShopee::MARKUP, 'comissao' => AfiliadosShopee::COMISSAO],
        ]);
    }

    /** Marca ou desmarca produtos (um, vários ou um grupo inteiro). */
    public function escolher(Request $request, AfiliadosShopee $afiliados): RedirectResponse
    {
        $dados = $request->validate([
            'escolhido' => ['required', 'boolean'],
            'produtos' => ['required_without:grupo', 'array'],
            'produtos.*' => ['integer'],
            'grupo' => ['required_without:produtos', Rule::in(array_keys(AfiliadosShopee::GRUPOS))],
        ]);

        $ids = isset($dados['grupo'])
            ? $afiliados->query($dados['grupo'])->pluck('id')->all()
            : $dados['produtos'];

        if (! $dados['escolhido']) {
            AfiliadoProduto::query()->where('canal', AfiliadosShopee::CANAL)->whereIn('product_id', $ids)->delete();

            return back();
        }

        $semCusto = 0;
        foreach (Product::query()->whereIn('id', $ids)->whereNull('parent_product_id')->get() as $p) {
            $custo = $p->cost_price !== null ? (float) $p->cost_price : null;
            $preco = AfiliadosShopee::preco($custo);
            if ($preco === null) {
                $semCusto++;

                continue;
            }
            AfiliadoProduto::query()->updateOrCreate(
                ['canal' => AfiliadosShopee::CANAL, 'product_id' => $p->id],
                ['sku_afiliado' => AfiliadosShopee::skuAfiliado($p->sku), 'custo' => $custo, 'preco' => $preco, 'escolhido_por' => $request->user()?->id],
            );
        }

        return back()->with($semCusto ? 'error' : 'success', $semCusto
            ? "{$semCusto} produto(s) sem custo cadastrado ficaram de fora: cadastre o custo para calcular o preço."
            : 'Escolha salva.');
    }
}
