<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cadastros\Models\CostCenter;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Financeiro\Models\CashFlowEntry;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Models\OrderChannelFee;
use App\Modules\Marketplace\Support\FlexDeliveryService;
use App\Support\Http\TableSort;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CashFlowController extends Controller
{
    private const PER_PAGE = 50;

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
        // Paginado no servidor (as duas tabelas da tela) — antes iam TODOS
        // os lançamentos e TODAS as linhas de venda (uma por item de pedido,
        // desde sempre) pro navegador, ~575 KB de HTML medido em produção
        // 2026-09-29. Busca/ordenação/plataforma viraram query param e os
        // totais (cards) continuam sobre o conjunto filtrado inteiro, só que
        // somados no SQL em vez de no front.
        $entriesSearch = TableSort::likeTerm($request->string('entries_search')->toString());
        $entriesSort = TableSort::resolve($request, [
            'entry_date' => 'entry_date',
            'description' => 'description',
            'type' => 'type',
            'amount' => 'amount',
            'costCenter' => 'cost_center_name',
        ], 'entry_date', 'desc', 'entries_');

        $entries = CashFlowEntry::query()
            ->with('costCenter:id,name', 'creator:id,name')
            ->when($entriesSearch, function ($query) use ($entriesSearch) {
                $query->where(function ($query) use ($entriesSearch) {
                    $query->where('description', 'like', $entriesSearch)
                        ->orWhereHas('costCenter', fn ($costCenter) => $costCenter->where('name', 'like', $entriesSearch));
                });
            })
            ->when(
                $entriesSort['column'] === 'cost_center_name',
                fn ($query) => $query->orderBy(CostCenter::query()->select('name')->whereColumn('cost_centers.id', 'cash_flow_entries.cost_center_id'), $entriesSort['direction']),
                fn ($query) => $query->orderBy($entriesSort['column'], $entriesSort['direction']),
            )
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['*'], 'entries_page')
            ->withQueryString();

        // Saldo/entradas/saídas sempre sobre TODOS os lançamentos (como já
        // era — a busca da tabela nunca mexeu nesses cards).
        $entryTotals = CashFlowEntry::query()
            ->selectRaw('COALESCE(SUM(CASE WHEN type = ? THEN amount ELSE 0 END), 0) as income', [CashFlowEntry::TYPE_INCOME])
            ->selectRaw('COALESCE(SUM(CASE WHEN type = ? THEN amount ELSE 0 END), 0) as expense', [CashFlowEntry::TYPE_EXPENSE])
            ->toBase()
            ->first();
        $income = round((float) $entryTotals->income, 2);
        $expense = round((float) $entryTotals->expense, 2);

        [$start, $end] = $this->resolveSalesPeriod($request);

        $platform = $request->string('platform')->toString();
        $platform = $platform !== '' ? $platform : null;
        $salesSearch = $request->string('search')->toString();
        $salesSort = TableSort::resolve($request, [
            'date' => 'ordered_at',
            'product_name' => 'product_name',
            'platform' => 'origin',
            'product_cost' => 'product_cost',
            'platform_fee' => 'platform_fee',
            'shipping_cost' => 'shipping_cost',
            'net_profit' => 'net_profit',
        ], 'ordered_at');

        $salesLines = $this->filteredSalesLines($start, $end, $platform, $salesSearch);

        $sales = DB::query()
            ->fromSub($salesLines, 'sales_lines')
            ->orderBy($salesSort['column'], $salesSort['direction'])
            ->orderByDesc('order_id')
            ->orderBy('item_id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (object $line) => $this->presentSalesLine($line));

        // Totais da listagem de vendas — seguem período + plataforma + busca,
        // pra bater com o que está na tabela (antes o front somava as linhas
        // filtradas). Sempre número, mesmo sem venda: pedido explícito
        // 2026-08-14 "se não tiver dado colocar 0,00".
        $salesTotals = DB::query()
            ->fromSub($salesLines, 'sales_lines')
            ->selectRaw('COALESCE(SUM(product_cost), 0) as cost, COALESCE(SUM(platform_fee), 0) as fee, COALESCE(SUM(shipping_cost), 0) as shipping, COALESCE(SUM(net_profit), 0) as net_profit')
            ->first();

        return Inertia::render('Admin/CashFlow/Index', [
            'entries' => $entries,
            'costCenters' => CostCenter::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'summary' => [
                'balance' => round($income - $expense, 2),
                'income' => $income,
                'expense' => $expense,
                // Valor em estoque = soma de todo produto pelo custo pago
                // no fornecedor × quantidade em estoque — mesma conta já
                // usada no Painel Financeiro (FinancialDashboardController).
                // Produto sem custo cadastrado conta como 0 aqui (mesmo
                // aviso de dado incompleto do resto da tela). Pedido
                // explícito 2026-08-14.
                'stockValue' => round((float) Product::query()
                    ->selectRaw('COALESCE(SUM(stock * COALESCE(cost_price, 0)), 0) as total')
                    ->value('total'), 2),
            ],
            // Listagem de lucro por venda — pedido explícito 2026-08-14:
            // data do pedido, produto, custo pago no fornecedor, comissão
            // da plataforma e lucro líquido linha a linha, pra ficar claro
            // de onde vem cada real do saldo mostrado acima. Padrão é
            // "todas as vendas, todos os períodos" (pedido explícito
            // 2026-08-14: "quero todas vendas todos periodos") — só filtra
            // se o usuário escolher De/Até na tela.
            'sales' => $sales,
            'salesTotals' => [
                'cost' => round((float) $salesTotals->cost, 2),
                'fee' => round((float) $salesTotals->fee, 2),
                'shipping' => round((float) $salesTotals->shipping, 2),
                'netProfit' => round((float) $salesTotals->net_profit, 2),
            ],
            // Opções do filtro de plataforma — as que têm venda no período
            // (antes saíam das linhas carregadas no front, mesma regra).
            'salesPlatforms' => $this->salesLinesQuery($start, $end)
                ->reorder()
                ->distinct()
                ->pluck('orders.origin')
                ->map(fn (string $origin) => ['value' => $origin, 'label' => $this->platformLabel($origin)])
                ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
                ->values(),
            'salesFilter' => [
                'start' => $start?->toDateString(),
                'end' => $end?->toDateString(),
                'platform' => $platform,
                'search' => $salesSearch,
                'sort' => $salesSort['key'],
                'direction' => $salesSort['direction'],
            ],
            'entriesFilter' => [
                'search' => $request->string('entries_search')->toString(),
                'sort' => $entriesSort['key'],
                'direction' => $entriesSort['direction'],
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
     * Linhas de "Margem de Contribuição por Venda" (uma por item de pedido)
     * já calculadas no SQL — mesma conta que antes era feita em PHP pedido a
     * pedido (ver salesLinesQuery()), agora como subquery pra poder paginar
     * e somar os totais sem carregar todas as vendas.
     */
    private function filteredSalesLines(?Carbon $start, ?Carbon $end, ?string $platform, string $search): Builder
    {
        $like = TableSort::likeTerm($search);
        $searchText = mb_strtolower(trim($search));
        // A busca do navegador também casava o nome da plataforma mostrado
        // na tabela — mantém mapeando o texto pros origins correspondentes.
        $matchingOrigins = $searchText === '' ? [] : array_keys(array_filter(
            self::CHANNEL_LABELS,
            fn (string $label) => str_contains(mb_strtolower($label), $searchText),
        ));

        $lines = $this->salesLinesQuery($start, $end)
            ->when($platform, fn ($query) => $query->where('orders.origin', $platform))
            ->when($like, function ($query) use ($like, $matchingOrigins) {
                $query->where(function ($query) use ($like, $matchingOrigins) {
                    $query->where('order_items.product_name', 'like', $like)
                        ->orWhere('orders.id', 'like', $like)
                        ->when($matchingOrigins, fn ($query) => $query->orWhereIn('orders.origin', $matchingOrigins));
                });
            })
            ->select([
                'orders.created_at as ordered_at',
                'orders.id as order_id',
                'order_items.id as item_id',
                'order_items.product_name',
                'orders.origin',
                'order_items.quantity',
                'order_items.subtotal as item_subtotal',
                'orders.subtotal as order_subtotal',
                'products.id as product_ref',
                'products.cost_price',
                'order_items.manual_cost_price',
                'order_channel_fees.id as fee_id',
                'order_channel_fees.fee_amount',
            ])
            // Frete pago pela LOJA por pedido (margem de contribuição, ver
            // ContributionMargin): pré-postagem dos Correios e entrega Flex.
            ->selectRaw(
                'COALESCE(correios.total, 0) + CASE WHEN channel_shipments.channel = ? AND channel_shipments.shipping_method = ? THEN ? ELSE 0 END as frete_loja',
                [MarketplaceAccount::CHANNEL_MERCADO_LIVRE, 'self_service', app(FlexDeliveryService::class)->costPerDelivery()],
            )
            // Amazon: o frete que o comprador paga vem pra loja (envio
            // pelos Correios da loja) — mesma regra de ContributionMargin::receitaSql().
            ->selectRaw('CASE WHEN orders.origin = ? THEN COALESCE(orders.shipping_cost, 0) ELSE 0 END as frete_recebido', [Order::ORIGIN_AMAZON]);

        // Rateio por item. Multiplica ANTES de dividir (e força decimal com
        // 1.0) pra não perder precisão nem cair em divisão inteira no
        // SQLite — o arredondamento fica igual ao round() do PHP de antes.
        $perItem = DB::query()->fromSub($lines, 'l')->select([
            'ordered_at', 'order_id', 'item_id', 'product_name', 'origin', 'item_subtotal',
        ])
            // Item com produto local mapeado: custo vem do cadastro do
            // produto (cost_price × quantidade). Sem produto (venda de
            // anúncio nunca trazido pro catálogo — autoImportProduct()
            // falhou/não suportado) não tem onde buscar isso, usa o valor
            // digitado à mão direto na linha (manual_cost_price, já é o
            // total, sem quantidade envolvida). Pedido explícito
            // 2026-08-14: "editar os que está sem custo também".
            ->selectRaw('CASE WHEN product_ref IS NOT NULL THEN ROUND(COALESCE(cost_price, 0) * quantity, 2) ELSE COALESCE(manual_cost_price, 0) END as product_cost')
            ->selectRaw('CASE WHEN product_ref IS NOT NULL THEN (CASE WHEN cost_price IS NOT NULL THEN 1 ELSE 0 END) ELSE (CASE WHEN manual_cost_price IS NOT NULL THEN 1 ELSE 0 END) END as has_cost')
            // Sem OrderChannelFee (canal não devolveu a taxa real, ou
            // pedido anterior à integração), a comissão é DESCONHECIDA —
            // nunca 0. Mesmo critério já usado em
            // MercadoLivreSalesController: inventar 0 aqui esconderia a
            // taxa de verdade e inflaria o lucro líquido mostrado.
            ->selectRaw('CASE WHEN fee_id IS NOT NULL THEN 1 ELSE 0 END as has_fee_data')
            // Comissão da plataforma é lançada por pedido, não por item —
            // rateia proporcionalmente ao valor de cada item quando o
            // pedido tem mais de um produto.
            ->selectRaw('CASE WHEN order_subtotal > 0 THEN ROUND(COALESCE(fee_amount, 0) * 1.0 * item_subtotal / order_subtotal, 2) ELSE 0 END as platform_fee')
            ->selectRaw('CASE WHEN order_subtotal > 0 THEN ROUND(frete_loja * 1.0 * item_subtotal / order_subtotal, 2) ELSE 0 END as item_frete_loja')
            ->selectRaw('CASE WHEN order_subtotal > 0 THEN ROUND(frete_recebido * 1.0 * item_subtotal / order_subtotal, 2) ELSE 0 END as item_frete_recebido');

        return DB::query()->fromSub($perItem, 'i')->select([
            'ordered_at', 'order_id', 'item_id', 'product_name', 'origin',
            'product_cost', 'has_cost', 'platform_fee', 'has_fee_data',
        ])
            ->selectRaw('ROUND(item_frete_loja - item_frete_recebido, 2) as shipping_cost')
            // Lucro líquido sem dado de comissão não desconta taxa nenhuma —
            // mostrado como incompleto no front (mesmo aviso do "sem
            // custo"), não como se a taxa fosse zero. Margem de contribuição
            // da linha: ADS não entra aqui (não é por venda — sai no total do
            // mês no Financeiro).
            ->selectRaw('ROUND(item_subtotal + item_frete_recebido - product_cost - (CASE WHEN has_fee_data = 1 THEN platform_fee ELSE 0 END) - item_frete_loja, 2) as net_profit');
    }

    /**
     * Base (joins + período + status de venda) das linhas de venda — um
     * registro por item de pedido. channelFee/channelShipment são HasOne:
     * junta só uma linha por pedido (a de menor id, como o eager-load
     * pegaria) pra item nunca aparecer duplicado.
     */
    private function salesLinesQuery(?Carbon $start, ?Carbon $end): Builder
    {
        $correios = CorreiosPrePostagem::query()
            ->toBase()
            ->where('status', CorreiosPrePostagem::STATUS_GERADA)
            ->whereNotNull('order_id')
            ->selectRaw('order_id, COALESCE(SUM(postage_price), 0) as total')
            ->groupBy('order_id');
        $firstFee = OrderChannelFee::query()->toBase()->selectRaw('order_id, MIN(id) as id')->groupBy('order_id');
        $firstShipment = ChannelShipment::query()->toBase()->selectRaw('order_id, MIN(id) as id')->groupBy('order_id');

        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            // Produto excluído (soft delete) conta como "sem produto", igual
            // ao eager-load items.product de antes.
            ->leftJoin('products', function ($join) {
                $join->on('products.id', '=', 'order_items.product_id')->whereNull('products.deleted_at');
            })
            ->leftJoinSub($firstFee, 'first_fee', 'first_fee.order_id', '=', 'orders.id')
            ->leftJoin('order_channel_fees', 'order_channel_fees.id', '=', 'first_fee.id')
            ->leftJoinSub($firstShipment, 'first_shipment', 'first_shipment.order_id', '=', 'orders.id')
            ->leftJoin('channel_shipments', 'channel_shipments.id', '=', 'first_shipment.id')
            ->leftJoinSub($correios, 'correios', 'correios.order_id', '=', 'orders.id')
            ->whereIn('orders.status', self::REVENUE_STATUSES)
            ->when($start, fn ($query) => $query->where('orders.created_at', '>=', $start))
            ->when($end, fn ($query) => $query->where('orders.created_at', '<=', $end));
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSalesLine(object $line): array
    {
        return [
            'date' => Carbon::parse($line->ordered_at)->toDateString(),
            'order_id' => (int) $line->order_id,
            'item_id' => (int) $line->item_id,
            'product_name' => $line->product_name,
            'product_cost' => round((float) $line->product_cost, 2),
            'has_cost' => (bool) $line->has_cost,
            'platform_fee' => round((float) $line->platform_fee, 2),
            'shipping_cost' => round((float) $line->shipping_cost, 2),
            'has_fee_data' => (bool) $line->has_fee_data,
            'platform' => $this->platformLabel($line->origin),
            'net_profit' => round((float) $line->net_profit, 2),
        ];
    }

    private function platformLabel(string $origin): string
    {
        return self::CHANNEL_LABELS[$origin] ?? ucfirst(str_replace('_', ' ', $origin));
    }

    /**
     * Comissão digitada à mão na própria tabela de "Lucro por Venda" —
     * pedido explícito 2026-08-14, pro caso comum de o canal não ter
     * devolvido a taxa real (ver has_fee_data em filteredSalesLines()). Grava
     * como OrderChannelFee normal (source=manual) — assim que salvo, a
     * linha some do estado "sem dado" igual a qualquer taxa vinda da API.
     * Editar a comissão de um pedido com mais de um produto afeta o
     * pedido inteiro (a taxa é por pedido, não por item — mesmo rateio
     * proporcional de filteredSalesLines() é reaplicado no próximo carregamento).
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
     * "Pago ao fornecedor" (incluindo linha "sem custo": pedido explícito
     * "editar os que está sem custo também"). O valor digitado é o custo
     * TOTAL daquela linha (já ×quantidade, é o que aparece na tela).
     *
     * Item com produto local mapeado: grava como cost_price UNITÁRIO no
     * produto (÷quantidade) — custo é atributo do produto, não do
     * pedido, então passa a valer pra essa venda e qualquer venda futura
     * do mesmo produto, igual a cadastrar em /admin/produtos.
     *
     * Item SEM produto mapeado (venda de anúncio nunca trazido pro
     * catálogo): não existe Product pra editar, grava o total direto no
     * próprio item (manual_cost_price) — vale só pra essa venda.
     */
    public function updateItemCost(Request $request, OrderItem $orderItem): RedirectResponse
    {
        $validated = $request->validate([
            'product_cost' => ['required', 'numeric', 'min:0'],
        ]);

        if ($orderItem->product) {
            if ($orderItem->quantity < 1) {
                return back()->with('error', 'Quantidade inválida nesse item, não dá pra calcular o custo unitário.');
            }

            $orderItem->product->update([
                'cost_price' => round($validated['product_cost'] / $orderItem->quantity, 2),
            ]);
        } else {
            $orderItem->update(['manual_cost_price' => $validated['product_cost']]);
        }

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
