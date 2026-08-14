<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cadastros\Models\CostCenter;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Financeiro\Models\CashFlowEntry;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\OrderChannelFee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CashFlowController extends Controller
{
    private const REVENUE_STATUSES = [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED];

    private const CHANNEL_LABELS = [
        MarketplaceAccount::CHANNEL_MERCADO_LIVRE => 'Mercado Livre',
        MarketplaceAccount::CHANNEL_SHOPEE => 'Shopee',
        MarketplaceAccount::CHANNEL_TIKTOK_SHOP => 'TikTok Shop',
        MarketplaceAccount::CHANNEL_AMAZON => 'Amazon',
        MarketplaceAccount::CHANNEL_SHEIN => 'Shein',
        Order::ORIGIN_STORE => 'Loja própria',
    ];

    public function index(Request $request): Response
    {
        $entries = CashFlowEntry::query()->with('costCenter:id,name', 'creator:id,name')->latest('entry_date')->get();

        $income = (float) $entries->where('type', CashFlowEntry::TYPE_INCOME)->sum('amount');
        $expense = (float) $entries->where('type', CashFlowEntry::TYPE_EXPENSE)->sum('amount');

        [$start, $end] = $this->resolveSalesPeriod($request);

        return Inertia::render('Admin/CashFlow/Index', [
            'entries' => $entries,
            'costCenters' => CostCenter::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'summary' => [
                'balance' => $income - $expense,
                'income' => $income,
                'expense' => $expense,
            ],
            // Listagem de lucro por venda — pedido explícito 2026-08-14:
            // data do pedido, produto, custo pago no fornecedor, comissão
            // da plataforma e lucro líquido linha a linha, pra ficar claro
            // de onde vem cada real do saldo mostrado acima. Padrão é
            // "todas as vendas, todos os períodos" (pedido explícito
            // 2026-08-14: "quero todas vendas todos periodos") — só filtra
            // se o usuário escolher De/Até na tela.
            'sales' => $this->salesBreakdown($start, $end),
            'salesFilter' => [
                'start' => $start?->toDateString(),
                'end' => $end?->toDateString(),
            ],
        ]);
    }

    /**
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function resolveSalesPeriod(Request $request): array
    {
        $start = null;
        $end = null;

        try {
            $start = $request->filled('start') ? Carbon::parse($request->string('start')->toString())->startOfDay() : null;
        } catch (\Throwable) {
            $start = null;
        }

        try {
            $end = $request->filled('end') ? Carbon::parse($request->string('end')->toString())->endOfDay() : null;
        } catch (\Throwable) {
            $end = null;
        }

        // Início depois do fim não faz sentido — troca em vez de devolver
        // uma listagem vazia sem explicação.
        if ($start && $end && $start->gt($end)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        return [$start, $end];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function salesBreakdown(?Carbon $start, ?Carbon $end): array
    {
        return Order::query()
            ->whereIn('status', self::REVENUE_STATUSES)
            ->when($start, fn ($query) => $query->where('created_at', '>=', $start))
            ->when($end, fn ($query) => $query->where('created_at', '<=', $end))
            ->with(['items.product:id,cost_price', 'channelFee'])
            ->latest('created_at')
            ->get()
            ->flatMap(function (Order $order) {
                // Sem OrderChannelFee (canal não devolveu a taxa real, ou
                // pedido anterior à integração), a comissão é DESCONHECIDA —
                // nunca 0. Mesmo critério já usado em
                // MercadoLivreSalesController: inventar 0 aqui esconderia a
                // taxa de verdade e inflaria o lucro líquido mostrado.
                // Achado real 2026-08-14: era exatamente isso que fazia a
                // comissão do Mercado Livre "sumir" nesta listagem.
                $hasFeeData = $order->channelFee !== null;
                $fee = (float) ($order->channelFee?->fee_amount ?? 0);
                $orderSubtotal = (float) $order->subtotal;

                return $order->items->map(function ($item) use ($order, $fee, $hasFeeData, $orderSubtotal) {
                    $itemSubtotal = (float) $item->subtotal;
                    // Comissão da plataforma é lançada por pedido, não por
                    // item — rateia proporcionalmente ao valor de cada
                    // item quando o pedido tem mais de um produto.
                    $itemFee = $orderSubtotal > 0 ? round($fee * ($itemSubtotal / $orderSubtotal), 2) : 0.0;
                    $costPrice = (float) ($item->product?->cost_price ?? 0);
                    $productCost = round($costPrice * $item->quantity, 2);
                    // Lucro líquido sem dado de comissão não desconta taxa
                    // nenhuma — mostrado como incompleto no front (mesmo
                    // aviso do "sem custo"), não como se a taxa fosse zero.
                    $netProfit = round($itemSubtotal - $productCost - ($hasFeeData ? $itemFee : 0), 2);

                    return [
                        'date' => $order->created_at->toDateString(),
                        'order_id' => $order->id,
                        'item_id' => $item->id,
                        'product_name' => $item->product_name,
                        'product_cost' => $productCost,
                        'has_cost' => $item->product?->cost_price !== null,
                        // Sem produto local mapeado (item de nota fiscal
                        // manual, ex.) não tem onde gravar custo — front
                        // desabilita a edição nesse caso.
                        'cost_editable' => $item->product_id !== null,
                        'platform_fee' => $itemFee,
                        'has_fee_data' => $hasFeeData,
                        'platform' => self::CHANNEL_LABELS[$order->origin] ?? ucfirst(str_replace('_', ' ', $order->origin)),
                        'net_profit' => $netProfit,
                    ];
                });
            })
            ->values()
            ->all();
    }

    /**
     * Comissão digitada à mão na própria tabela de "Lucro por Venda" —
     * pedido explícito 2026-08-14, pro caso comum de o canal não ter
     * devolvido a taxa real (ver has_fee_data em salesBreakdown()). Grava
     * como OrderChannelFee normal (source=manual) — assim que salvo, a
     * linha some do estado "sem dado" igual a qualquer taxa vinda da API.
     * Editar a comissão de um pedido com mais de um produto afeta o
     * pedido inteiro (a taxa é por pedido, não por item — mesmo rateio
     * proporcional de salesBreakdown() é reaplicado no próximo carregamento).
     */
    public function updateSaleFee(Request $request, Order $order): RedirectResponse
    {
        $validated = $request->validate([
            'fee_amount' => ['required', 'numeric', 'min:0'],
        ]);

        OrderChannelFee::query()->updateOrCreate(
            ['order_id' => $order->id, 'channel' => $order->origin],
            [
                'gross_amount' => $order->subtotal,
                'fee_amount' => $validated['fee_amount'],
                'source' => OrderChannelFee::SOURCE_MANUAL,
                'computed_at' => now(),
            ],
        );

        return back()->with('success', 'Comissão atualizada.');
    }

    /**
     * Custo do fornecedor editado à mão na tabela de "Lucro por Venda" —
     * mesmo pedido explícito 2026-08-14 da comissão editável, agora pro
     * "Pago ao fornecedor". O valor digitado é o custo TOTAL daquela
     * linha (já ×quantidade, é o que aparece na tela) — grava de volta
     * como cost_price UNITÁRIO no produto (÷quantidade), porque custo é
     * atributo do produto, não do pedido: passa a valer pra essa venda e
     * pra qualquer venda futura do mesmo produto, igual a cadastrar o
     * custo em /admin/produtos.
     */
    public function updateItemCost(Request $request, OrderItem $orderItem): RedirectResponse
    {
        $validated = $request->validate([
            'product_cost' => ['required', 'numeric', 'min:0'],
        ]);

        if ($orderItem->product === null || $orderItem->quantity < 1) {
            return back()->with('error', 'Esse item não tem produto cadastrado pra gravar o custo.');
        }

        $orderItem->product->update([
            'cost_price' => round($validated['product_cost'] / $orderItem->quantity, 2),
        ]);

        return back()->with('success', 'Custo do fornecedor atualizado.');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);
        $validated['created_by'] = $request->user()->id;

        CashFlowEntry::create($validated);

        return back()->with('success', 'Lançamento adicionado ao fluxo de caixa.');
    }

    public function update(Request $request, CashFlowEntry $cashFlowEntry): RedirectResponse
    {
        $cashFlowEntry->update($this->validated($request));

        return back()->with('success', 'Lançamento atualizado.');
    }

    public function destroy(CashFlowEntry $cashFlowEntry): RedirectResponse
    {
        $cashFlowEntry->delete();

        return back()->with('success', 'Lançamento removido.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in([CashFlowEntry::TYPE_INCOME, CashFlowEntry::TYPE_EXPENSE])],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'cost_center_id' => ['nullable', 'exists:cost_centers,id'],
            'entry_date' => ['required', 'date'],
        ]);
    }
}
