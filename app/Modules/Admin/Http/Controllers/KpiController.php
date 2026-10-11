<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class KpiController extends Controller
{
    public function index(): Response
    {
        $startOfMonth = Carbon::today()->startOfMonth();

        $ordersMonth = Order::query()->where('created_at', '>=', $startOfMonth);
        $totalOrdersMonth = (clone $ordersMonth)->count();
        $cancelledOrdersMonth = (clone $ordersMonth)->where('status', Order::STATUS_CANCELLED)->count();
        $validOrders = Order::query()->whereNot('status', Order::STATUS_CANCELLED);
        $validOrdersMonth = (clone $ordersMonth)->whereNot('status', Order::STATUS_CANCELLED);

        // BUG REAL 2026-08-17 ("as métricas do kazakora ainda têm
        // informação incorreta", achado ao vivo: taxa de conversão
        // mostrando 200%): conversionRate comparava TODO pedido válido do
        // mês (qualquer canal) contra uniqueVisitorsMonth, que só existe
        // pra visita real na loja própria (SiteVisit não rastreia
        // marketplace nenhum). No mês corrente, 100% dos 227 pedidos
        // vieram de Shopee/ML/TikTok/Amazon — zero da loja própria —
        // então "conversão" comparava dois funis sem relação nenhuma
        // (comprador que nunca visitou o site vindo de outro canal). Taxa
        // de conversão só faz sentido pro funil visita->compra da PRÓPRIA
        // loja (ORIGIN_STORE); pedido de marketplace não entra nem no
        // numerador nem no denominador dessa conta.
        $storeOrdersMonth = (clone $validOrdersMonth)->where('origin', Order::ORIGIN_STORE);

        // subtotal, não total — 'total' inclui frete (não é receita do
        // vendedor), ver comentário em FinancialDashboardController::index().
        $averageTicket = (clone $validOrders)->count() > 0
            ? (float) (clone $validOrders)->sum('subtotal') / (clone $validOrders)->count()
            : 0.0;

        // IP diferente, não visitor_id (cookie) — mesma definição de
        // "visita" usada em DashboardController, pedido explícito do
        // usuário 2026-08-07 (refresh/navegação do mesmo visitante não pode
        // contar como visita nova).
        $uniqueVisitorsMonth = SiteVisit::query()
            ->where('created_at', '>=', $startOfMonth)
            ->distinct()
            ->count('ip');

        $conversionRate = $uniqueVisitorsMonth > 0
            ? ($storeOrdersMonth->count() / $uniqueVisitorsMonth) * 100
            : 0.0;

        $productsCount = Product::query()->count();
        $lowStockCount = Product::query()->where('stock', '<=', 5)->count();

        return Inertia::render('Admin/Indicadores/Index', [
            'kpis' => [
                'averageTicket' => $averageTicket,
                'conversionRate' => round($conversionRate, 2),
                'cancellationRate' => $totalOrdersMonth > 0 ? round(($cancelledOrdersMonth / $totalOrdersMonth) * 100, 2) : 0.0,
                'lowStockRate' => $productsCount > 0 ? round(($lowStockCount / $productsCount) * 100, 2) : 0.0,
                'uniqueVisitorsMonth' => $uniqueVisitorsMonth,
                'ordersMonth' => $totalOrdersMonth,
            ],
        ]);
    }
}
