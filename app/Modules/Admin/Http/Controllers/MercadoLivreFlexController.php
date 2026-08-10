<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\FlexBillingPeriod;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\FlexDeliveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
    public function index(Request $request, FlexDeliveryService $flex): Response
    {
        $today = Carbon::today();
        $monthStart = $today->copy()->startOfMonth();
        $currentCycle = $flex->cycleContaining($today);

        // Lista individual — pedido explícito 2026-08-10, complementar aos
        // cards agregados: filtro por mês (formato "YYYY-MM", sempre
        // aplicado — a lista nunca vem sem recorte de mês, pra nunca
        // carregar o histórico inteiro de uma vez) e por número de pedido
        // (interno ou o da própria Mercado Livre), os dois já com todos os
        // dados de detalhe (cliente/endereço/produto/valor) carregados de
        // uma vez — clicar na linha só abre um modal com o que já veio,
        // sem precisar de uma segunda requisição.
        $selectedMonth = $request->filled('mes')
            ? Carbon::createFromFormat('Y-m', $request->string('mes'))
            : $today;

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
            'deliveries' => $this->deliveriesList($selectedMonth, $request->string('pedido')->toString() ?: null),
            'filters' => [
                'mes' => $selectedMonth->format('Y-m'),
                'pedido' => $request->string('pedido')->toString() ?: null,
            ],
        ]);
    }

    private function deliveriesList(Carbon $month, ?string $orderSearch): array
    {
        return ChannelShipment::query()
            ->where('channel', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)
            ->where('shipping_method', 'self_service')
            ->whereBetween(DB::raw('COALESCE(confirmed_at, created_at)'), [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->when($orderSearch, fn ($query) => $query->whereHas(
                'order',
                fn ($orderQuery) => $orderQuery->where('external_order_id', 'like', "%{$orderSearch}%")
                    ->orWhere('id', $orderSearch),
            ))
            ->with([
                // Order::created_at aqui já É a data real do pedido
                // informada pela própria Mercado Livre, não a data de
                // importação — OrderImportService::importNormalized()
                // força created_at pro "placed_at" que o driver devolve
                // (MercadoLivreDriver::importOrder(), vem de date_created
                // da API), justamente pra nunca mostrar a data errada.
                // "placed_at" NÃO é uma coluna própria — não existe no
                // schema, essa é a razão de ser desse ajuste.
                'order:id,external_order_id,shipping_name,shipping_street,shipping_number,shipping_neighborhood,shipping_city,shipping_state,shipping_zip,total,created_at',
                'order.items:id,order_id,product_name,quantity',
            ])
            ->orderByDesc(DB::raw('COALESCE(confirmed_at, created_at)'))
            ->get()
            ->map(fn (ChannelShipment $shipment) => [
                'id' => $shipment->id,
                'orderId' => $shipment->order_id,
                'externalOrderId' => $shipment->order?->external_order_id,
                'customerName' => $shipment->order?->shipping_name,
                'address' => $this->formatAddress($shipment->order),
                'products' => $shipment->order?->items->map(fn ($item) => [
                    'name' => $item->product_name,
                    'quantity' => $item->quantity,
                ])->all() ?? [],
                'total' => $shipment->order ? (float) $shipment->order->total : null,
                'orderPlacedAt' => $shipment->order?->created_at ?? $shipment->confirmed_at ?? $shipment->created_at,
            ])
            ->values()
            ->all();
    }

    private function formatAddress(?Order $order): ?string
    {
        if (! $order) {
            return null;
        }

        $cityState = implode('/', array_filter([$order->shipping_city, $order->shipping_state]));

        $parts = array_filter([
            trim(($order->shipping_street ?? '').' '.($order->shipping_number ?? '')),
            $order->shipping_neighborhood,
            $cityState ?: null,
            $order->shipping_zip,
        ]);

        return $parts ? implode(' — ', $parts) : null;
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
