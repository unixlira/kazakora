<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Models\FlexBillingPeriod;
use App\Modules\Marketplace\Support\FlexDeliveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controle de custo do Mercado Envios Flex — pedido explícito 2026-08-10.
 * Cobrança quinzenal (dia 1-15 / 16-fim do mês), valor por entrega
 * editável (Setting, ver FlexDeliveryService), histórico dos ciclos já
 * fechados (FlexBillingPeriod, alimentado por CheckFlexBillingCycle).
 */
class MercadoLivreFlexController extends Controller
{
    public function index(FlexDeliveryService $flex): Response
    {
        $today = Carbon::today();
        $monthStart = $today->copy()->startOfMonth();
        $currentCycle = $flex->cycleContaining($today);

        return Inertia::render('Admin/Integracoes/MercadoLivre/Flex', [
            'costPerDelivery' => $flex->costPerDelivery(),
            'currentCycle' => array_merge(
                ['start' => $currentCycle['start']->toDateString(), 'end' => $currentCycle['end']->toDateString()],
                $flex->summaryForPeriod($currentCycle['start'], $currentCycle['end']),
            ),
            'monthToDate' => $flex->summaryForPeriod($monthStart, $today),
            'history' => FlexBillingPeriod::query()
                ->orderByDesc('period_start')
                ->limit(24)
                ->get()
                ->map(fn (FlexBillingPeriod $period) => [
                    'id' => $period->id,
                    'periodStart' => $period->period_start->toDateString(),
                    'periodEnd' => $period->period_end->toDateString(),
                    'deliveriesCount' => $period->deliveries_count,
                    'costPerDelivery' => (float) $period->cost_per_delivery,
                    'totalAmount' => (float) $period->total_amount,
                    'emailSentAt' => $period->email_sent_at,
                    'emailError' => $period->email_error,
                ]),
        ]);
    }

    public function update(Request $request, FlexDeliveryService $flex): RedirectResponse
    {
        $validated = $request->validate([
            'cost_per_delivery' => ['required', 'numeric', 'min:0', 'max:9999.99'],
        ]);

        $flex->updateCostPerDelivery((float) $validated['cost_per_delivery']);

        return back()->with('success', 'Valor por entrega Flex atualizado.');
    }
}
