<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Support\OrderBump;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Marketing › Order bump (pedido do Lira 2026-11-24, "ver se aumenta o
 * ticket"): liga/desliga a caixa do checkout, o produto geral e o desconto
 * padrão, e mostra exibições, aceites, receita extra e ticket médio com e sem
 * bump. As regras de escolha e preço estão em OrderBump.
 */
class OrderBumpController extends Controller
{
    private const PERIODOS = [7, 30, 90];

    public function index(Request $request, OrderBump $orderBump): Response
    {
        $dias = in_array($request->integer('dias'), self::PERIODOS, true) ? $request->integer('dias') : 30;
        $ate = now();
        $de = now()->subDays($dias)->startOfDay();

        return Inertia::render('Admin/OrderBump/Index', [
            'dias' => $dias,
            'periodos' => self::PERIODOS,
            'resumo' => $orderBump->resumo($de, $ate),
            'config' => [
                'ligado' => $orderBump->ligado(),
                'produto_id' => $orderBump->produtoGeralId(),
                'desconto_pct' => $orderBump->descontoPadrao(),
            ],
            'regras' => [
                'margem_minima_pct' => (int) round(OrderBump::MARGEM_MINIMA * 100),
                'teto_do_subtotal_pct' => (int) round(OrderBump::TETO_DO_SUBTOTAL * 100),
                'teto_minimo' => OrderBump::TETO_MINIMO,
                'dias_ranking' => OrderBump::DIAS_DO_RANKING,
            ],
            'produtos' => ProductController::produtosParaOrderBump(),
        ]);
    }

    public function salvar(Request $request, OrderBump $orderBump): RedirectResponse
    {
        $dados = $request->validate([
            'ligado' => ['required', 'boolean'],
            'produto_id' => ['nullable', 'integer', 'exists:products,id'],
            'desconto_pct' => ['required', 'numeric', 'min:0', 'max:90'],
        ]);

        $orderBump->configurar((bool) $dados['ligado'], $dados['produto_id'] ?? null, (float) $dados['desconto_pct']);

        return back()->with('success', 'Order bump salvo.');
    }
}
