<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Publicidade › Régua de CPA (pedido do Lira em 2026-11-25): calculadora do
 * método do professor de tráfego. Do custo sai o preço, o CPA máximo (0 a 0),
 * o CPA alvo (metade), quanto cada carrinho pode custar, o plano de teste
 * (criativos × R$/dia × dias) e o diagnóstico de um criativo.
 *
 * Só leitura: entrega os produtos do catálogo (custo e preço de cadastro) e
 * as taxas oficiais da loja (cartão e Pix das ofertas, Simples e insumos da
 * config da loja). Nada é gravado no banco.
 */
class ReguaCpaController extends Controller
{
    /** Boleto: tarifa fixa do gateway por boleto pago, em R$. */
    private const BOLETO_FIXO = 3.49;

    public function index(): Response
    {
        return Inertia::render('Admin/ReguaCpa/Index', [
            'produtos' => Product::query()
                ->where('is_active', true)
                ->where('cost_price', '>', 0)
                ->orderBy('name')
                ->get(['id', 'name', 'price', 'cost_price', 'so_ferrakora'])
                ->map(fn (Product $p) => [
                    'id' => $p->id,
                    'nome' => $p->name,
                    'preco' => round((float) $p->price, 2),
                    'custo' => round((float) $p->cost_price, 2),
                    'loja' => $p->so_ferrakora ? 'FerraKora' : 'KazaKora',
                ])
                ->values(),
            'taxas' => [
                'cartao' => (float) config('ofertas.taxa_cartao', 4.98),
                'pix' => (float) config('ofertas.taxa_pix', 0.99),
                'boleto_fixo' => self::BOLETO_FIXO,
                'imposto' => (float) config('loja.simples.aliquota', 4.5),
                'insumos' => (float) config('loja.insumos_por_pedido', 2.0),
                'frete' => (float) config('ofertas.frete_estimado', 25),
            ],
        ]);
    }
}
