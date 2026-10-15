<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Support\Indicadores\PainelDeAlertas;
use App\Modules\Admin\Support\Indicadores\SerieDiaria;
use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Support\ContributionMargin;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class KpiController extends Controller
{
    public function index(Request $request, PainelDeAlertas $painel, SerieDiaria $serie, ContributionMargin $margem): Response
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
        // Só kazakora.com (2026-10-14): pedido do FerraKora/Beauty Kora não
        // conta contra os visitantes da loja.
        $storeOrdersMonth = (clone $validOrdersMonth)->doCanal(Order::ORIGIN_STORE);

        // subtotal, não total — 'total' inclui frete (não é receita do
        // vendedor), ver comentário em FinancialDashboardController::index().
        $averageTicket = (clone $validOrders)->count() > 0
            ? (float) (clone $validOrders)->sum('subtotal') / (clone $validOrders)->count()
            : 0.0;

        // Visitante único = cookie kazakora_visitor; sem OK de cookies, um
        // por IP no dia (pedido 2026-10-11, mesma conta do
        // DashboardController). Refresh/navegação do mesmo visitante não
        // conta de novo.
        $uniqueVisitorsMonth = SiteVisit::visitantesPorDia(
            SiteVisit::query()->daLoja()->where('created_at', '>=', $startOfMonth)
        )->pluck('visitante')->unique()->count();

        $conversionRate = $uniqueVisitorsMonth > 0
            ? ($storeOrdersMonth->count() / $uniqueVisitorsMonth) * 100
            : 0.0;

        $productsCount = Product::query()->count();
        $lowStockCount = Product::query()->where('stock', '<=', 5)->count();

        // Indicadores com alerta por canal (2026-10-14): últimos N dias
        // completos contra os N anteriores, ver PainelDeAlertas.
        // Tela só de cards e gráficos (2026-10-14): os 3 períodos, a
        // composição da receita (conta oficial) e a série diária vão juntos,
        // e o filtro de período/canal troca na tela sem voltar ao servidor.
        // Dias completos: 10 min de cache não muda nada além dos alertas de
        // operação.
        $dias = (int) $request->integer('dias', 7);
        $dias = in_array($dias, [7, 14, 30], true) ? $dias : 7;
        $dados = Cache::remember('indicadores:graficos:'.Carbon::today()->toDateString(), now()->addMinutes(10), function () use ($painel, $serie, $margem) {
            $paineis = [];
            $composicao = [];
            foreach ([7, 14, 30] as $d) {
                $paineis[$d] = $painel->gerar($d);
                $composicao[$d] = $margem->porCanal(Carbon::today()->subDays($d), Carbon::today());
            }

            return ['paineis' => $paineis, 'composicao' => $composicao, 'serie' => $serie->gerar()];
        });

        return Inertia::render('Admin/Indicadores/Index', [
            'painel' => $dados['paineis'][$dias],
            'paineis' => $dados['paineis'],
            'composicao' => $dados['composicao'],
            'serie' => $dados['serie'],
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
