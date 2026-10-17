<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Support\BuscaDePedidos;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderPaymentFinalizer;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Services\InvoiceService;
use App\Modules\Fiscal\Support\PackDoPedido;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockManager;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\LabelFetchService;
use App\Modules\Marketplace\Support\TipoDeEnvio;
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

    /**
     * Status do banco que cada opção do filtro traz. "Pendente" (pending)
     * inclui awaiting_payment, que no admin também aparece como "Pendente"
     * (statusLabels.js, Lira 2026-10-16). Só o filtro da listagem; o banco e
     * a validação da troca de status continuam com os valores originais.
     */
    private const STATUS_DO_FILTRO = [
        Order::STATUS_PENDING => [Order::STATUS_PENDING, Order::STATUS_AWAITING_PAYMENT],
    ];

    /** @return list<string> */
    private static function statusDoFiltro(string $status): array
    {
        return self::STATUS_DO_FILTRO[$status] ?? [$status];
    }

    private const CHANNELS = [
        Order::ORIGIN_STORE,
        Order::ORIGIN_MERCADO_LIVRE,
        Order::ORIGIN_SHOPEE,
        Order::ORIGIN_TIKTOK_SHOP,
        Order::ORIGIN_AMAZON,
    ];

    public function index(Request $request): Response
    {
        // Filtro "Marketplace" (2026-10-16): um select só, no lugar dos antigos
        // "Site" e "Marketplace". Links antigos (?origin= e ?site=ferrakora)
        // continuam caindo no canal certo.
        $marketplace = (string) ($request->query('marketplace') ?? $request->query('origin') ?? $request->query('site') ?? '');
        if (! array_key_exists($marketplace, Order::MARKETPLACES)) {
            $marketplace = '';
        }

        $de = $this->dataDoFiltro($request->query('de'));
        $ate = $this->dataDoFiltro($request->query('ate'));
        $busca = BuscaDePedidos::normalizar(is_string($request->query('search')) ? $request->query('search') : '');

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
                // Tipo de envio (Flex, Mercado Envios, Full...) na coluna Envios.
                'channelShipment:id,order_id,channel,shipping_method',
            ])
            ->withCount('items')
            // 'loja' = só kazakora.com; FerraKora/Beauty Kora pelo site_origem;
            // marketplaces pelo origin (TikTok Shop e Amazon via Bling inclusive).
            ->when($marketplace !== '', fn ($query) => Order::filtrarCanal($query, $marketplace))
            ->when(in_array($request->query('status'), self::STATUSES, true), fn ($query) => $query->whereIn('status', self::statusDoFiltro($request->query('status'))))
            ->when($de, fn ($query) => $query->where('created_at', '>=', $de->copy()->startOfDay()))
            ->when($ate, fn ($query) => $query->where('created_at', '<=', $ate->copy()->endOfDay()))
            // Busca (2026-10-16): regras em BuscaDePedidos. Fica num where
            // agrupado, então não anula marketplace, status e período.
            ->when($busca !== '', fn ($query) => BuscaDePedidos::aplicar($query, $busca))
            ->latest()
            ->latest('id')
            ->paginate(50)
            ->withQueryString()
            // Mesma tradução do KoraSync (TipoDeEnvio): o selo da listagem
            // diz como a caixa sai, não só o canal (2026-10-16).
            ->through(function (Order $order) {
                $envio = TipoDeEnvio::doPedido($order);
                $order->setAttribute('shipping_type', $envio['tipo']);
                $order->setAttribute('shipping_type_label', $envio['label']);
                $order->setAttribute('shipping_type_short', $envio['curto']);
                $order->unsetRelation('channelShipment');

                return $order;
            });

        return Inertia::render('Admin/Orders/Index', [
            'orders' => $orders,
            'statuses' => self::STATUSES,
            'marketplaces' => collect(Order::MARKETPLACES)
                ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                ->values(),
            'filters' => [
                'marketplace' => $marketplace,
                'status' => in_array($request->query('status'), self::STATUSES, true) ? $request->query('status') : '',
                'search' => $busca,
                'de' => $de?->toDateString(),
                'ate' => $ate?->toDateString(),
            ],
        ]);
    }

    private function dataDoFiltro(mixed $valor): ?Carbon
    {
        if (! is_string($valor) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $valor);
        } catch (Throwable) {
            return null;
        }
    }

    public function show(Order $order): Response
    {
        $order->load(['items.product:id,sku,name,variation,price,discount_percentage,discount_amount,stock,is_active', 'user:id,name,email,phone,cpf', 'invoice', 'channelShipment', 'latestCorreiosPrePostagem'])
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
            'fulfillmentEvents' => OrderFulfillmentEvent::compactar($order->fulfillmentEvents)[0]->values(),
            'packingVideos' => $order->packingVideos()->get()->map->paraTela()->values(),
            'operationStatement' => $this->operationStatement($order),
            'detalhe' => $this->detalheCompleto($order),
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

        // BUG REAL 2026-09-29: sem trava de status, editar itens de um
        // pedido cancelado (estoque já devolvido por restoreStockIfNeeded)
        // ou que já nasceu cancelado (nunca debitou — ver OrderImportService)
        // movia estoque de novo, sobre unidades que este pedido não segura
        // mais. Só pedido pago, com o estoque ainda debitado, tem o que
        // corrigir aqui. Rechecado dentro da transação, com lock, abaixo.
        $itemsEditableError = 'Só dá pra corrigir itens de pedido pago com o estoque ainda debitado — este pedido está cancelado/enviado ou já teve o estoque devolvido. Os itens não foram alterados.';

        if (! $this->itemsAreEditable($order)) {
            return back()->with('error', $itemsEditableError);
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

        $edited = DB::transaction(function () use ($order, $validated, $stock) {
            // Mesmo check de cima, agora com o pedido travado — um
            // cancelamento chegando (webhook/varredura) entre a validação e
            // aqui devolveria o estoque e esta edição mexeria nele de novo.
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            if (! $lockedOrder || ! $this->itemsAreEditable($lockedOrder)) {
                return false;
            }

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

            return true;
        });

        if (! $edited) {
            return back()->with('error', $itemsEditableError);
        }

        return back()->with('success', 'Itens do pedido atualizados. Produto, quantidade, totais e estoque local foram corrigidos.');
    }

    /**
     * Ver updateItems() — BUG REAL 2026-09-29: só pedido pago com o estoque
     * ainda debitado (stock_restored_at nulo) pode ter itens corrigidos.
     */
    private function itemsAreEditable(Order $order): bool
    {
        return $order->status === Order::STATUS_PAID && $order->stock_restored_at === null;
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
            return back()->with('success', 'A etiqueta já está pronta e guardada — ela sai na impressora quando o operador concluir a separação no KoraSync (desde 2026-09-06 nada imprime sozinho).');
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
            ? back()->with('success', 'Etiqueta ficou pronta agora e está guardada — imprime quando a separação for concluída no KoraSync.')
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
    public function printLabel(Order $order, Request $request): HttpResponse
    {
        $order->loadMissing('channelShipment');
        $shipment = $order->channelShipment;

        abort_unless($shipment?->label_path && Storage::disk('local')->exists($shipment->label_path), 404, 'Etiqueta ainda não foi baixada pra este pedido.');

        $caminho = $shipment->label_path;

        // ?original=1 devolve as 2 folhas originais do canal, guardadas
        // junto quando a etiqueta combinada do Mercado Livre é usada (ver
        // LabelFetchService). Serve pra quando a combinada rasgar, borrar
        // ou o leitor recusar: reimprime o formato de sempre sem consultar
        // o canal de novo. Caminho por convenção, sufixo "-original".
        if ($request->boolean('original')) {
            $alternativo = preg_replace('/\.pdf$/', '-original.pdf', $caminho);

            if ($alternativo !== $caminho && Storage::disk('local')->exists($alternativo)) {
                $caminho = $alternativo;
            }
        }

        return response(Storage::disk('local')->get($caminho), 200, [
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
    /**
     * Tudo o que o pedido tem, para a tela de detalhe do admin (pedido do
     * Lira 2026-10-16: o detalhe é o ponto central de validação e
     * atendimento). Só dado que já existe no banco; o que não foi gravado
     * (ex.: parcelas do cartão) não aparece.
     *
     * @return array<string, mixed>
     */
    private function detalheCompleto(Order $order): array
    {
        $order->loadMissing(['payments', 'marketplaceClaims', 'correiosPrePostagens', 'ferrakoraCliente']);

        $envio = TipoDeEnvio::doPedido($order);
        $invoice = $order->invoice;
        $shipment = $order->channelShipment;
        $pre = $order->latestCorreiosPrePostagem;
        $cliente = $order->user ?? $order->ferrakoraCliente;
        $rastreio = $order->trackingCode();

        $devolucoes = $invoice?->chave_acesso
            ? Invoice::query()
                ->whereHas('order', fn ($query) => $query->where('origin', Order::ORIGIN_SALES_RETURN_INVOICE)->where('fiscal_referenced_nfe_key', $invoice->chave_acesso))
                ->orderBy('id')
                ->get(['id', 'numero', 'serie', 'status', 'valor_total', 'autorizada_em'])
                ->map(fn (Invoice $nota) => [
                    'id' => $nota->id,
                    'numero' => $nota->numero,
                    'serie' => $nota->serie,
                    'status' => $nota->status,
                    'valor_total' => $nota->valor_total,
                    'autorizada_em' => $nota->autorizada_em,
                ])
                ->values()
            : collect();

        $mercadoPago = Schema::hasTable('mercado_pago_pagamentos')
            ? \App\Modules\Financeiro\Models\MercadoPagoPagamento::query()
                ->where('order_id', $order->id)
                ->orderBy('data_criacao')
                ->get(['mp_payment_id', 'data_criacao', 'data_aprovacao', 'data_liberacao', 'status', 'status_detalhe', 'tipo_operacao', 'canal', 'meio_pagamento', 'valor', 'liquido', 'reembolsado'])
            : collect();

        $pack = $order->channel_pack_id
            ? Order::query()
                ->where('origin', $order->origin)
                ->where('channel_pack_id', $order->channel_pack_id)
                ->whereKeyNot($order->id)
                ->orderBy('id')
                ->get(['id', 'external_order_id', 'status', 'total'])
            : collect();

        return [
            'cliente' => [
                'nome' => $cliente?->name ?? $order->shipping_name,
                'documento' => $order->buyer_document ?: $cliente?->cpf,
                'inscricao_estadual' => $order->buyer_state_registration,
                'email' => $order->shipping_email ?: $cliente?->email,
                'telefone' => $order->shipping_phone ?: $cliente?->phone,
                'whatsapp' => $order->shipping_whatsapp,
                'apelido_no_canal' => $order->channel_buyer_nickname,
                'conta' => $order->user ? 'KazaKora' : ($order->ferrakoraCliente ? 'FerraKora' : null),
            ],
            'endereco' => [
                'destinatario' => $order->shipping_recipient_name ?: $order->shipping_name,
                'rua' => $order->shipping_street,
                'numero' => $order->shipping_number,
                'complemento' => $order->shipping_complement,
                'bairro' => $order->shipping_neighborhood,
                'cidade' => $order->shipping_city,
                'uf' => $order->shipping_state,
                'cep' => $order->shipping_zip,
            ],
            'canal' => [
                'origin' => $order->origin,
                'site' => Order::ROTULOS_SITE[$order->site_origem] ?? null,
                'numero' => $order->numero,
                'pedido_no_canal' => $order->external_order_id,
                'pack' => $order->channel_pack_id,
                'pedidos_do_pack' => $pack,
                'criado_em' => $order->created_at,
                'atualizado_em' => $order->updated_at,
                'embalado_em' => $order->packed_at,
                'pronto_para_coleta_em' => $order->ready_for_pickup_at,
                'coletado_em' => $order->collected_at,
                'em_disputa_desde' => $order->disputed_at,
            ],
            'itens' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'nome' => $item->product_name,
                'sku' => $item->product?->sku,
                'variacao' => $item->product?->variation,
                'anuncio_no_canal' => $item->external_item_id,
                'variacao_no_canal' => $item->external_model_id,
                'quantidade' => (int) $item->quantity,
                'preco' => (float) $item->product_price,
                'subtotal' => (float) $item->subtotal,
                'tipo' => $item->item_type,
            ])->values(),
            'valores' => [
                'subtotal' => (float) $order->subtotal,
                'frete' => (float) $order->shipping_cost,
                'cupom' => $order->coupon_code,
                // discount_amount já soma Pix e desconto da plataforma (mesma
                // conta da área do cliente, CheckoutController::showOrder).
                'desconto_cupom' => round((float) $order->discount_amount - (float) $order->pix_discount_amount - (float) $order->desconto_plataforma_amount, 2),
                'desconto_pix' => (float) $order->pix_discount_amount,
                'desconto_plataforma' => (float) $order->desconto_plataforma_amount,
                'desconto_total' => (float) $order->discount_amount,
                'total' => (float) $order->total,
            ],
            'pagamentos' => $order->payments->sortBy('id')->map(fn ($pagamento) => [
                'id' => $pagamento->id,
                'provedor' => $pagamento->provider,
                'meio' => $pagamento->method_type,
                'status' => $pagamento->status,
                'valor' => (float) $pagamento->amount,
                'transacao' => $pagamento->mercadopago_payment_id ?? $pagamento->stripe_payment_intent_id,
                'pedido_no_provedor' => $pagamento->mercadopago_order_id,
                'boleto_url' => $pagamento->boleto_url,
                'expira_em' => $pagamento->expira_em,
                'criado_em' => $pagamento->created_at,
                'atualizado_em' => $pagamento->updated_at,
            ])->values(),
            'mercado_pago' => $mercadoPago->map(fn ($mp) => [
                'transacao' => $mp->mp_payment_id,
                'status' => $mp->status,
                'status_detalhe' => $mp->status_detalhe,
                'tipo_operacao' => $mp->tipo_operacao,
                'meio' => $mp->meio_pagamento,
                'valor' => (float) $mp->valor,
                'liquido' => (float) $mp->liquido,
                'reembolsado' => (float) $mp->reembolsado,
                'criado_em' => $mp->data_criacao,
                'aprovado_em' => $mp->data_aprovacao,
                'liberado_em' => $mp->data_liberacao,
            ])->values(),
            'nota' => $invoice ? [
                'id' => $invoice->id,
                'status' => $invoice->status,
                'numero' => $invoice->numero,
                'serie' => $invoice->serie,
                'chave' => $invoice->chave_acesso,
                'valor_total' => $invoice->valor_total,
                'protocolo' => $invoice->protocolo_autorizacao,
                'autorizada_em' => $invoice->autorizada_em,
                'motivo_rejeicao' => $invoice->motivo_rejeicao,
                'cancelada_em' => $invoice->cancelada_em,
                'motivo_cancelamento' => $invoice->motivo_cancelamento,
                'tem_danfe' => (bool) $invoice->danfe_path,
                'tem_xml' => (bool) $invoice->xml_path,
            ] : null,
            'devolucao' => [
                'estornado_em' => $order->estornado_em,
                'estorno_origem' => $order->estorno_origem,
                'estoque_devolvido_em' => $order->stock_restored_at,
                'notas_de_devolucao' => $devolucoes,
                'reclamacoes' => $order->marketplaceClaims->map(fn ($claim) => [
                    'id' => $claim->id,
                    'numero' => $claim->external_claim_id,
                    'tipo' => $claim->type,
                    'etapa' => $claim->stage,
                    'status' => $claim->status,
                    'resolucao' => $claim->resolution,
                    'aberta_em' => $claim->claim_created_at,
                    'atualizada_em' => $claim->claim_updated_at,
                ])->values(),
                'devolvido_pelo_canal_em' => $shipment?->channel_returned_at,
                'resolucao' => $shipment?->return_resolution,
                'resolvida_em' => $shipment?->return_resolved_at,
                'observacao' => $shipment?->return_note,
            ],
            'envio' => [
                'tipo' => $envio['tipo'],
                'label' => $envio['label'],
                'curto' => $envio['curto'],
                'transportadora' => $order->shipping_carrier_name,
                'servico' => $pre?->service_label,
                'metodo_no_canal' => $shipment?->shipping_method,
                'rastreio' => $rastreio,
                'rastreio_url' => $rastreio && preg_match('/^[A-Z]{2}\d{9}[A-Z]{2}$/', $rastreio)
                    ? 'https://rastreamento.correios.com.br/app/index.php?objetos='.$rastreio
                    : null,
                'rastreio_cliente_url' => route('rastreio.ver', $order->trackingRef()),
                'canal' => $shipment ? [
                    'id_no_canal' => $shipment->external_shipment_id,
                    'status' => $shipment->status,
                    'status_no_canal' => $shipment->channel_status,
                    'substatus_no_canal' => $shipment->channel_substatus,
                    'confirmado_em' => $shipment->confirmed_at,
                    'etiqueta_pronta_em' => $shipment->label_ready_at,
                    'agendado_para' => $shipment->scheduled_for,
                    'entrega_prometida' => $shipment->promised_delivery_on,
                    'enviado_em' => $shipment->channel_shipped_at,
                    'primeira_tentativa_em' => $shipment->channel_first_visit_at,
                    'entregue_em' => $shipment->channel_delivered_at,
                    'nao_entregue_em' => $shipment->channel_not_delivered_at,
                    'cancelado_em' => $shipment->channel_cancelled_at,
                    'consultado_em' => $shipment->channel_status_checked_at,
                ] : null,
                'pre_postagens' => $order->correiosPrePostagens->map(fn ($item) => [
                    'id' => $item->id,
                    'status' => $item->status,
                    'servico' => $item->service_label,
                    'codigo_servico' => $item->service_code,
                    'codigo_objeto' => $item->codigo_objeto,
                    'id_correios' => $item->correios_id,
                    'qr_payload' => $item->qr_payload,
                    'valor_postagem' => $item->postage_price !== null ? (float) $item->postage_price : null,
                    'peso_gramas' => $item->weight_grams,
                    'formato' => $item->dimension_format,
                    'altura' => $item->dimension_height,
                    'largura' => $item->dimension_width,
                    'comprimento' => $item->dimension_length,
                    'diametro' => $item->dimension_diameter,
                    'erro' => $item->error_message,
                    'criada_em' => $item->created_at,
                ])->values(),
            ],
        ];
    }

    private function operationStatement(Order $order): array
    {
        $cost = DB::table('order_items as oi')
            ->leftJoin('products as p', 'p.id', '=', 'oi.product_id')
            ->where('oi.order_id', $order->id)
            ->selectRaw('COALESCE(SUM(COALESCE(oi.quantity * p.cost_price, oi.manual_cost_price, 0)), 0) as product_cost,
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

        // Carrinho do Mercado Livre: a nota é uma só pro carrinho inteiro.
        // Cancelar ela junto com UM pedido derrubaria a nota dos itens que
        // continuam vendidos — avisa e não mexe.
        if ($avisoDoCarrinho = app(PackDoPedido::class)->avisoDeCancelamento($order)) {
            return $avisoDoCarrinho;
        }

        if (! $order->invoice || $order->invoice->status !== Invoice::STATUS_AUTHORIZED) {
            return null;
        }

        // Fora das 24h o cancelamento tem multa ou nem é mais possível: a
        // decisão é de quem está na tela da nota, nunca automática.
        $janela = $order->invoice->janelaDeCancelamento();

        if ($janela === Invoice::JANELA_EXTEMPORANEA) {
            return 'Pedido cancelado, mas a NF-e passou das 24h. Se a mercadoria não saiu, cancele pela tela da nota (até 480h, com multa estimada de R$ '.number_format($order->invoice->multaCancelamentoExtemporaneo(), 2, ',', '.').'); se saiu, registre a devolução lá.';
        }

        if ($janela === Invoice::JANELA_EXPIRADA) {
            return 'Pedido cancelado, mas a NF-e passou de 480h e não pode mais ser cancelada. Registre a devolução na tela da nota.';
        }

        try {
            $invoices->cancel($order, "Cancelamento do pedido #{$order->id}");

            return null;
        } catch (Throwable $exception) {
            Log::error('nfe.cancel.failed', ['order_id' => $order->id, 'message' => $exception->getMessage()]);

            return "Pedido cancelado, mas a NF-e não pôde ser cancelada automaticamente na SEFAZ: {$exception->getMessage()}";
        }
    }

    /** Vídeo da embalagem: toca no navegador (com Range) ou baixa com ?baixar=1. */
    public function packingVideo(Request $request, Order $order, \App\Modules\Checkout\Models\OrderPackingVideo $packingVideo): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        abort_unless($packingVideo->order_id === $order->id, 404);
        $disco = \Illuminate\Support\Facades\Storage::disk('local');
        abort_unless($disco->exists($packingVideo->path), 404, 'Vídeo não encontrado (pode ter passado dos 90 dias).');

        $nome = "embalagem-pedido-{$order->id}.".pathinfo($packingVideo->path, PATHINFO_EXTENSION);

        return $request->boolean('baixar')
            ? response()->download($disco->path($packingVideo->path), $nome)
            : response()->file($disco->path($packingVideo->path), ['Content-Type' => str_ends_with($packingVideo->path, '.mp4') ? 'video/mp4' : 'video/webm']);
    }
}
