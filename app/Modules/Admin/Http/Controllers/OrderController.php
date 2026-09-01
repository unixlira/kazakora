<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Support\OrderPaymentFinalizer;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Services\InvoiceService;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockManager;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\LabelFetchService;
use App\Notifications\OrderStatusUpdated;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class OrderController extends Controller
{
    private const STATUSES = [
        Order::STATUS_PENDING,
        Order::STATUS_PAID,
        Order::STATUS_SHIPPED,
        Order::STATUS_COMPLETED,
        Order::STATUS_CANCELLED,
    ];

    private const CHANNELS = [
        Order::ORIGIN_STORE,
        Order::ORIGIN_MERCADO_LIVRE,
        Order::ORIGIN_SHOPEE,
        Order::ORIGIN_TIKTOK_SHOP,
        Order::ORIGIN_AMAZON,
    ];

    public function index(Request $request): Response
    {
        $orders = Order::query()
            ->nonPurchaseReturn()
            // Relações latestOfMany não podem usar seleção parcial de colunas
            // aqui: o Laravel monta subqueries que também carregam order_id, e
            // o MySQL rejeita com "Column 'order_id' is ambiguous". Isso já
            // havia sido confirmado em latestEmailLog e se repetiu em
            // latestCorreiosPrePostagem depois da coluna Correios entrar na
            // listagem.
            ->with([
                'user:id,name,email',
                'invoice:id,order_id,status',
                'latestEmailLog',
                'latestCorreiosPrePostagem',
            ])
            ->withCount('items')
            ->when($request->filled('origin'), fn ($query) => $query->where('origin', $request->string('origin')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = trim($request->string('search')->toString());
                $digits = preg_replace('/\D+/', '', $search);
                $parsedDate = $this->parseOrderSearchDate($search);

                if ($digits !== '' && strlen($digits) <= 10) {
                    $query->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $digits]);
                }

                $query->where(function ($inner) use ($search, $digits, $parsedDate) {
                    $inner->where('external_order_id', 'like', "%{$search}%")
                        ->orWhere('shipping_name', 'like', "%{$search}%")
                        ->orWhere('shipping_email', 'like', "%{$search}%")
                        ->orWhere('shipping_phone', 'like', "%{$search}%")
                        ->orWhereHas('user', fn ($userQuery) => $userQuery
                            ->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%"))
                        ->orWhereHas('invoice', fn ($invoiceQuery) => $invoiceQuery
                            ->where('numero', 'like', "%{$search}%")
                            ->orWhere('chave_acesso', 'like', "%{$search}%"));

                    if ($digits !== '') {
                        if (strlen($digits) <= 10) {
                            $inner->orWhere('id', (int) $digits);
                        }

                        $inner->orWhere('external_order_id', 'like', "%{$digits}%")
                            ->orWhere('shipping_phone', 'like', "%{$digits}%");
                    }

                    if ($parsedDate) {
                        $inner->orWhereDate('created_at', $parsedDate->toDateString());
                    }
                });
            })
            ->latest()
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Admin/Orders/Index', [
            'orders' => $orders,
            'statuses' => self::STATUSES,
            'channels' => self::CHANNELS,
            'filters' => $request->only('origin', 'search'),
        ]);
    }

    private function parseOrderSearchDate(string $value): ?Carbon
    {
        $value = trim($value);

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d'] as $format) {
            try {
                $date = Carbon::createFromFormat($format, $value);
                if ($date && $date->format($format) === $value) {
                    return $date;
                }
            } catch (Throwable) {
                // continua tentando os outros formatos
            }
        }

        if (preg_match('/^\d{1,2}\/\d{1,2}$/', $value)) {
            [$day, $month] = array_map('intval', explode('/', $value));

            try {
                return Carbon::createFromDate(now()->year, $month, $day);
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    public function show(Order $order): Response
    {
        $order->load(['items.product:id,sku,name,price,discount_percentage,discount_amount,stock,is_active', 'user:id,name,email', 'invoice', 'channelShipment', 'latestCorreiosPrePostagem'])
            ->loadSum('items as units_count', 'quantity');

        $selectedProductIds = $order->items
            ->pluck('product_id')
            ->filter()
            ->unique()
            ->values();

        $products = Product::query()
            ->select('id', 'sku', 'name', 'price', 'discount_percentage', 'discount_amount', 'stock', 'is_active')
            ->where(function ($query) use ($selectedProductIds) {
                $query->where('is_active', true);

                if ($selectedProductIds->isNotEmpty()) {
                    $query->orWhereIn('id', $selectedProductIds);
                }
            })
            ->orderBy('name')
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'sku' => $product->sku,
                'name' => $product->name,
                'stock' => $product->stock,
                'is_active' => $product->is_active,
                'final_price' => $product->final_price,
            ]);

        return Inertia::render('Admin/Orders/Show', [
            'order' => $order,
            'products' => $products,
            'statuses' => self::STATUSES,
            'invoiceGenerationLogs' => $order->invoiceGenerationLogs,
            'emailLogs' => $order->emailLogs,
            'fulfillmentEvents' => $order->fulfillmentEvents,
            'operationStatement' => $this->operationStatement($order),
            'auditLogs' => AuditLog::query()
                ->where('entity', class_basename(Order::class))
                ->where('entity_id', $order->id)
                ->with('user:id,name')
                ->latest('created_at')
                ->get(),
        ]);
    }

    public function update(Request $request, Order $order, InvoiceService $invoices, OrderPaymentFinalizer $finalizer): RedirectResponse
    {
        // 'origin' opcional (sometimes|nullable) — pedido explícito
        // 2026-08-21 (pedido #559, importado errado como "loja"/site em vez
        // do canal certo): corrige um erro de digitação/seleção na hora de
        // importar, sem mexer em mais nada do pedido. NÃO recria
        // ChannelShipment/Invoice nem dispara nenhum job automático (esses
        // só disparam na criação/transição pra pago de verdade, ver
        // OrderImportService) — é só a correção do dado em si; se o pedido
        // corrigido precisar do pipeline automático de verdade (etiqueta,
        // nota), isso é responsabilidade de outra ação (ex.: reimportar).
        $validated = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
            'origin' => ['sometimes', 'nullable', Rule::in(self::CHANNELS)],
        ]);

        $previousStatus = $order->status;
        $statusChanged = $previousStatus !== $validated['status'];
        $originChanged = array_key_exists('origin', $validated) && $validated['origin'] !== null && $validated['origin'] !== $order->origin;

        if ($originChanged) {
            // Mesma trava única (origin, external_order_id) que
            // OrderImportService usa pra não duplicar pedido — corrigir o
            // canal pra um que já tem outro pedido de verdade com esse
            // mesmo external_order_id seria uma colisão de dados, não uma
            // correção.
            $conflict = Order::query()
                ->where('origin', $validated['origin'])
                ->where('external_order_id', $order->external_order_id)
                ->whereKeyNot($order->id)
                ->exists();

            if ($order->external_order_id && $conflict) {
                return back()->with('error', 'Já existe outro pedido com esse mesmo ID externo nesse canal — não é possível trocar pra ele.');
            }
        }

        // Nunca grava origin=null — 'nullable' na validação acima é só pra
        // aceitar o campo ausente/vazio sem 422 quando só o status está
        // sendo trocado (ver template, o mesmo form manda os dois campos
        // juntos, mas outros chamadores futuros do PATCH podem mandar só
        // status).
        $order->update($originChanged ? $validated : ['status' => $validated['status']]);

        if ($statusChanged && $order->user) {
            $order->user->notify(new OrderStatusUpdated($order));
        }

        $warnings = [];

        if ($statusChanged && $validated['status'] === Order::STATUS_CANCELLED) {
            if ($invoiceWarning = $this->cancelInvoiceIfAuthorized($order, $invoices)) {
                $warnings[] = $invoiceWarning;
            }

            // Sem gate por status anterior: refundOrder() só age em Payment
            // já capturado/autorizado (nunca existe nenhum pra um pedido que
            // nunca chegou a ser cobrado), então chamar sempre é seguro.
            foreach ($finalizer->refundOrder($order) as $refundError) {
                Log::error('stripe.refund.order_cancel_failed', ['order_id' => $order->id, 'message' => $refundError]);
                $warnings[] = "Reembolso: {$refundError}";
            }

            // Só devolve estoque se o pedido nunca chegou a ser enviado —
            // se já saiu (shipped/completed), o produto saiu de verdade e
            // devolver aqui criaria estoque fantasma.
            if (! in_array($previousStatus, [Order::STATUS_SHIPPED, Order::STATUS_COMPLETED], true)) {
                $finalizer->restoreStockIfNeeded($order, 'Pedido cancelado pelo admin');
            }
        }

        $message = $originChanged ? 'Status e canal do pedido atualizados.' : 'Status do pedido atualizado.';
        $response = back()->with('success', $message);

        return $warnings ? $response->with('warning', implode(' ', $warnings)) : $response;
    }

    public function updateItems(Request $request, Order $order, StockManager $stock): RedirectResponse
    {
        $order->loadMissing(['items', 'invoice']);

        if (in_array($order->invoice?->status, ['signed', 'sent', 'authorized'], true)) {
            return back()->with('error', 'Este pedido já tem NF-e assinada/enviada/autorizada. Para não deixar nota e pedido divergentes, os itens não foram alterados.');
        }

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'distinct'],
            'items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        $currentItemIds = $order->items->pluck('id')->sort()->values()->all();
        $submittedItemIds = collect($validated['items'])->pluck('id')->sort()->values()->all();

        if ($currentItemIds !== $submittedItemIds) {
            return back()->with('error', 'A lista de itens mudou enquanto você editava. Recarregue o pedido e tente novamente.');
        }

        DB::transaction(function () use ($order, $validated, $stock) {
            $items = $order->items()->lockForUpdate()->get()->keyBy('id');
            $oldValues = $items->map(fn ($item) => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'quantity' => $item->quantity,
                'subtotal' => $item->subtotal,
            ])->values()->all();

            $newValues = [];
            $newSubtotal = 0.0;

            foreach ($validated['items'] as $row) {
                $item = $items->get($row['id']);
                $product = Product::query()->whereKey($row['product_id'])->firstOrFail();
                $oldProductId = $item->product_id ? (int) $item->product_id : null;
                $newProductId = (int) $product->id;
                $oldQuantity = (int) $item->quantity;
                $newQuantity = (int) $row['quantity'];

                if ($oldProductId && $oldProductId !== $newProductId) {
                    $oldProduct = Product::query()->whereKey($oldProductId)->first();
                    if ($oldProduct) {
                        $stock->adjust($oldProduct, $oldQuantity, StockMovement::TYPE_ADJUSTMENT, reason: "Correção do pedido #{$order->id}: produto trocado no admin", reference: $order);
                    }
                }

                if ($oldProductId === $newProductId) {
                    $stockDelta = $oldQuantity - $newQuantity;
                    if ($stockDelta !== 0) {
                        $stock->adjust($product, $stockDelta, StockMovement::TYPE_ADJUSTMENT, reason: "Correção do pedido #{$order->id}: quantidade ajustada no admin", reference: $order);
                    }
                } else {
                    $stock->adjust($product, -$newQuantity, StockMovement::TYPE_ADJUSTMENT, reason: "Correção do pedido #{$order->id}: produto trocado no admin", reference: $order);
                }

                $unitPrice = (float) ($item->product_price ?: $product->unitPriceForQuantity($newQuantity));
                $lineSubtotal = round($unitPrice * $newQuantity, 2);

                $item->update([
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_price' => $unitPrice,
                    'quantity' => $newQuantity,
                    'subtotal' => $lineSubtotal,
                ]);

                $newSubtotal += $lineSubtotal;
                $newValues[] = [
                    'id' => $item->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity' => $newQuantity,
                    'subtotal' => $lineSubtotal,
                ];
            }

            $shippingCost = (float) ($order->shipping_cost ?? 0);
            $discountAmount = (float) ($order->discount_amount ?? 0);

            $order->update([
                'subtotal' => round($newSubtotal, 2),
                'total' => round(max(0, $newSubtotal + $shippingCost - $discountAmount), 2),
            ]);

            AuditLog::create([
                'user_id' => Auth::id(),
                'action' => AuditLog::ACTION_UPDATE,
                'entity' => class_basename(Order::class),
                'entity_id' => $order->id,
                'old_values' => ['items' => $oldValues],
                'new_values' => ['items' => $newValues],
                'created_at' => now(),
            ]);
        });

        return back()->with('success', 'Itens do pedido atualizados. Produto, quantidade, totais e estoque local foram corrigidos.');
    }

    /**
     * Botão "Verificar etiqueta agora" (Admin/Orders/Show) — pedido explícito
     * 2026-08-13: usuário voltando repetidas vezes pedindo pra destravar
     * etiqueta de pedido parado (Shopee, depois Mercado Livre) porque não
     * tinha nenhum jeito de checar/forçar isso sozinho pelo painel — só via
     * intervenção manual direto no servidor. Reaproveita o MESMO serviço
     * que CheckShipmentLabelJob usa nos bastidores (LabelFetchService::
     * attempt(), seguro pra chamar quantas vezes quiser: se o canal ainda
     * não liberou, só devolve "não pronta" sem side-effect nenhum; se já
     * tem PrintJob, o firstOrCreate() de dentro de attempt() não duplica).
     * Chama síncrono, direto do clique, pro usuário ver o resultado real na
     * hora — a mesma pergunta "por que não imprimiu" agora tem resposta
     * própria na tela, sem precisar pedir pra alguém investigar no servidor.
     */
    public function checkLabel(Order $order): RedirectResponse
    {
        $order->loadMissing('channelShipment');
        $shipment = $order->channelShipment;

        if (! $shipment) {
            return back()->with('error', 'Este pedido não tem envio de canal registrado — nada pra verificar.');
        }

        if (in_array($shipment->status, [ChannelShipment::STATUS_LABEL_READY, ChannelShipment::STATUS_LABEL_DOWNLOADED], true)) {
            return back()->with('success', 'A etiqueta já está pronta — o KoraSync já deve ter imprimido ou vai imprimir no próximo ciclo.');
        }

        // 2026-08-31: geração automática de etiqueta pro TikTok Shop via
        // Bling exige plano pago (R$200/mês) que o usuário decidiu não
        // contratar — envio desse canal agora é resolvido manualmente
        // direto no painel do TikTok. Esse botão consultando o Bling às
        // vezes encontra um rastreio que só existe porque o próprio usuário
        // já tratou o envio manual lá, e reimprime por cima aqui (pedido
        // #1132, mesmo dia — etiqueta duplicada sem necessidade). Bloqueado
        // até essa decisão mudar.
        if ($shipment->channel === MarketplaceAccount::CHANNEL_TIKTOK_SHOP) {
            return back()->with('error', 'TikTok Shop: etiqueta é tratada manualmente direto no painel do TikTok (geração automática via Bling exige plano pago). Esse botão fica desligado pra esse canal pra não duplicar etiqueta.');
        }

        $ready = app(LabelFetchService::class)->attempt($shipment);

        return $ready
            ? back()->with('success', 'Etiqueta ficou pronta agora — o KoraSync já pode imprimir.')
            : back()->with('error', "O {$shipment->channel} ainda não liberou a etiqueta desse envio. Isso é decidido do lado do canal (Shopee/Mercado Livre), não é algo que dá pra forçar — o sistema continua tentando sozinho automaticamente.");
    }

    /**
     * Pedido explícito 2026-08-21: reimprimir uma etiqueta já pronta direto
     * pelo navegador do admin, sem depender do KoraSync — feito no dia em
     * que o agente local ficou preso (job já visto antes fica pra sempre
     * marcado localmente, mesmo reaberto no servidor, ver
     * QueueEngine.SyncFromServerAsync no repo do KoraSync) e nenhum jeito
     * de reimprimir na hora existia. `inline` (não `attachment`, ver
     * InvoiceController::danfe()) — abre o PDF direto no visualizador do
     * navegador, pronto pra Ctrl+P, sem precisar baixar e abrir manual.
     * Serve o arquivo exatamente como está — não gera nada novo, não
     * consulta o canal (isso é o que checkLabel() acima já faz).
     */
    public function printLabel(Order $order): HttpResponse
    {
        $order->loadMissing('channelShipment');
        $shipment = $order->channelShipment;

        abort_unless($shipment?->label_path && Storage::disk('local')->exists($shipment->label_path), 404, 'Etiqueta ainda não foi baixada pra este pedido.');

        return response(Storage::disk('local')->get($shipment->label_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"etiqueta-pedido-{$order->id}.pdf\"",
        ]);
    }

    /**
     * Botão "Corrigir etiquetas de hoje" (Admin/Orders/Index) — pedido
     * explícito 2026-08-21, urgente: várias etiquetas de Mercado Livre e
     * Shopee do dia foram baixadas/marcadas como prontas ENQUANTO o layout
     * combinado ainda tinha bugs (versão deitada errada, depois desligada
     * de vez). checkLabel() (ver acima) não resolve isso sozinho — ele nem
     * tenta de novo quando o shipment já está STATUS_LABEL_READY/
     * _DOWNLOADED, então essas etiquetas ficavam presas com o PDF antigo
     * pra sempre. Este botão substitui o comando de tinker manual que seria
     * necessário: busca de novo no canal (idempotente, mesmo método que
     * checkLabel() usa) e REGRAVA label_path com o código corrigido, pra
     * TODOS os envios de Mercado Livre/Shopee com etiqueta pronta HOJE —
     * depois é só usar "Reimprimir etiqueta" em cada pedido normalmente.
     * Síncrono de propósito (mesmo racional de checkLabel()): usuário
     * precisa ver o resultado (quantos corrigiram, quantos falharam) na
     * hora, sem esperar fila.
     */
    public function fixTodaysLabels(): RedirectResponse
    {
        $shipments = ChannelShipment::query()
            ->whereIn('channel', [MarketplaceAccount::CHANNEL_MERCADO_LIVRE, MarketplaceAccount::CHANNEL_SHOPEE])
            ->whereIn('status', [ChannelShipment::STATUS_LABEL_READY, ChannelShipment::STATUS_LABEL_DOWNLOADED])
            ->whereDate('label_ready_at', today())
            ->with('order')
            ->get();

        if ($shipments->isEmpty()) {
            return back()->with('success', 'Nenhuma etiqueta de hoje pra corrigir — todas já estão com o layout atual, ou nenhuma foi gerada ainda hoje.');
        }

        $fixed = 0;
        $failed = [];

        foreach ($shipments as $shipment) {
            try {
                if (app(LabelFetchService::class)->attempt($shipment)) {
                    $fixed++;
                } else {
                    $failed[] = $shipment->order_id;
                }
            } catch (Throwable $exception) {
                Log::warning('admin.orders.fix_todays_labels_failed', [
                    'shipment_id' => $shipment->id,
                    'order_id' => $shipment->order_id,
                    'message' => $exception->getMessage(),
                ]);
                $failed[] = $shipment->order_id;
            }
        }

        $message = "{$fixed} etiqueta(s) corrigida(s) — já pode reimprimir cada pedido normalmente.";

        if ($failed !== []) {
            $message .= ' Falharam: pedido(s) #'.implode(', #', $failed).' (veja o log pra detalhe).';

            return back()->with('warning', $message);
        }

        return back()->with('success', $message);
    }

    /**
     * Extrato financeiro daquele pedido específico.
     *
     * Quando existe extrato importado do marketplace, ele é a fonte de verdade:
     * mostra subtotal anunciado, quanto o cliente pagou, descontos bancados pela
     * plataforma/vendedor, taxas, frete, recebido/a receber e lucro usando custo
     * cadastrado no KazaKora. Quando ainda não existe extrato (ex.: Amazon por
     * enquanto), cai para a visão parcial do pedido local + taxa real/manual se
     * ela existir, sem inventar valor liquidado.
     */
    private function operationStatement(Order $order): array
    {
        $cost = DB::table('order_items as oi')
            ->leftJoin('products as p', 'p.id', '=', 'oi.product_id')
            ->where('oi.order_id', $order->id)
            ->selectRaw('COALESCE(SUM(oi.quantity * COALESCE(oi.manual_cost_price, p.cost_price, 0)), 0) as product_cost,
                SUM(CASE WHEN oi.id IS NOT NULL AND COALESCE(oi.manual_cost_price, p.cost_price) IS NULL THEN 1 ELSE 0 END) as cost_missing_items')
            ->first();

        $productCost = round((float) ($cost?->product_cost ?? 0), 2);
        $costMissingItems = (int) ($cost?->cost_missing_items ?? 0);

        $rows = collect();

        if ($order->external_order_id && Schema::hasTable('marketplace_settlement_details')) {
            $rows = DB::table('marketplace_settlement_details')
                ->where('channel', $order->origin)
                ->where('external_order_id', $order->external_order_id)
                ->where('transaction_type', 'Pedido')
                ->orderBy('external_sku_id')
                ->get();
        }

        if ($rows->isNotEmpty()) {
            $payout = round((float) $rows->sum('payout_amount'), 2);
            $productNetSales = round((float) $rows->sum('product_net_sales'), 2);

            return [
                'source' => 'settlement',
                'sourceLabel' => 'Extrato real do marketplace',
                'hasSettlement' => true,
                'isComplete' => $costMissingItems === 0,
                'summary' => [
                    'orderSubtotal' => round((float) $order->subtotal, 2),
                    'orderTotal' => round((float) $order->total, 2),
                    'advertisedSubtotal' => round((float) $rows->sum('item_subtotal_before_discounts'), 2),
                    'customerPayment' => round((float) $rows->sum('customer_payment'), 2),
                    'productNetSales' => $productNetSales,
                    'payoutAmount' => $payout,
                    'paidPayoutAmount' => round((float) $rows->where('status', 'Pagos')->sum('payout_amount'), 2),
                    'pendingPayoutAmount' => round((float) $rows->where('status', '<>', 'Pagos')->sum('payout_amount'), 2),
                    'sellerDiscounts' => round(abs((float) $rows->sum('seller_discounts')), 2),
                    'platformProductDiscounts' => round(abs((float) $rows->sum('platform_product_discounts')), 2),
                    'platformCouponDiscounts' => round(abs((float) $rows->sum('platform_coupon_discounts')), 2),
                    'platformShippingDiscounts' => round(abs((float) $rows->sum('platform_shipping_discounts')), 2),
                    'netShippingImpact' => round((float) $rows->sum('net_shipping_cost'), 2),
                    'platformFeesTaxes' => round(abs((float) $rows->sum('platform_fees_taxes')), 2),
                    'affiliateCommissions' => round(abs((float) $rows->sum('affiliate_commissions')), 2),
                    'gmvMaxAdFee' => round(abs((float) $rows->sum('gmv_max_ad_fee')), 2),
                    'productCost' => $productCost,
                    'costMissingItems' => $costMissingItems,
                    'grossProfitKnown' => round($productNetSales - $productCost, 2),
                    'netProfitKnown' => round($payout - $productCost, 2),
                ],
                'lines' => $rows->map(fn ($row) => [
                    'skuId' => $row->external_sku_id,
                    'skuName' => $row->sku_name,
                    'productName' => $row->product_name,
                    'quantity' => (int) $row->quantity,
                    'advertisedSubtotal' => round((float) $row->item_subtotal_before_discounts, 2),
                    'customerPayment' => round((float) $row->customer_payment, 2),
                    'productNetSales' => round((float) $row->product_net_sales, 2),
                    'payoutAmount' => round((float) $row->payout_amount, 2),
                    'status' => $row->status,
                ])->values()->all(),
            ];
        }

        $fee = DB::table('order_channel_fees')
            ->where('order_id', $order->id)
            ->where('channel', $order->origin)
            ->first();

        $gross = round((float) ($fee?->gross_amount ?? $order->subtotal), 2);
        $feeAmount = $fee ? round(abs((float) $fee->fee_amount), 2) : null;
        $payout = $feeAmount === null ? null : round($gross - $feeAmount, 2);

        return [
            'source' => $fee ? 'channel_fee' : 'local_order',
            'sourceLabel' => $fee ? 'Pedido local + taxa do canal' : 'Pedido local sem extrato importado',
            'hasSettlement' => false,
            'isComplete' => $fee !== null && $costMissingItems === 0,
            'summary' => [
                'orderSubtotal' => round((float) $order->subtotal, 2),
                'orderTotal' => round((float) $order->total, 2),
                'advertisedSubtotal' => round((float) $order->subtotal, 2),
                'customerPayment' => round((float) $order->total, 2),
                'productNetSales' => $gross,
                'payoutAmount' => $payout,
                'paidPayoutAmount' => null,
                'pendingPayoutAmount' => null,
                'sellerDiscounts' => round(abs((float) $order->discount_amount), 2),
                'platformProductDiscounts' => 0.0,
                'platformCouponDiscounts' => 0.0,
                'platformShippingDiscounts' => 0.0,
                'netShippingImpact' => 0.0,
                'platformFeesTaxes' => $feeAmount,
                'affiliateCommissions' => 0.0,
                'gmvMaxAdFee' => 0.0,
                'productCost' => $productCost,
                'costMissingItems' => $costMissingItems,
                'grossProfitKnown' => round($gross - $productCost, 2),
                'netProfitKnown' => $payout === null ? null : round($payout - $productCost, 2),
            ],
            'lines' => [],
        ];
    }

    /**
     * Cancela a NF-e do pedido junto (Etapa 5) quando ele tem uma nota
     * autorizada. Nunca bloqueia a mudança de status do pedido em si — se o
     * cancelamento na SEFAZ falhar (ex: prazo de 24h expirado), o admin
     * precisa ser avisado pra tratar manualmente, mas o pedido já foi
     * marcado como cancelado de qualquer forma.
     */
    private function cancelInvoiceIfAuthorized(Order $order, InvoiceService $invoices): ?string
    {
        $order->loadMissing('invoice');

        if (! $order->invoice || $order->invoice->status !== Invoice::STATUS_AUTHORIZED) {
            return null;
        }

        if ($order->invoice->autorizada_em?->diffInHours(now()) >= 24) {
            return 'Pedido cancelado, mas o prazo de 24h para cancelar a NF-e já expirou. Emita uma nota fiscal de devolução para venda retornada.';
        }

        try {
            $invoices->cancel($order, "Cancelamento do pedido #{$order->id}");

            return null;
        } catch (Throwable $exception) {
            Log::error('nfe.cancel.failed', ['order_id' => $order->id, 'message' => $exception->getMessage()]);

            return "Pedido cancelado, mas a NF-e não pôde ser cancelada automaticamente na SEFAZ: {$exception->getMessage()}";
        }
    }
}
