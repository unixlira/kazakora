<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\PrintJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Painel do KoraSync — pedido do usuário 2026-08-05: virou um painel de
 * expedição estilo "chamada de senha de consultório" (pedido atual em
 * destaque + fila decrescente), não mais um monitor de status de PrintJob.
 * O motivo real: o painel antigo mostrava status de impressão, não o que
 * precisa ser separado pra embalar — resultou numa devolução perdida (2
 * itens do mesmo pedido, só 1 percebido). Fila é por Order (status "paid",
 * ainda não embalado/enviado), não por PrintJob — todo canal entra
 * (inclusive venda direto na loja), e mostra quantidade de itens (soma de
 * quantity, não linhas) + nome de cada produto.
 */
class PrintJobController extends Controller
{
    private const CHANNEL_LABELS = [
        MarketplaceAccount::CHANNEL_SHOPEE => 'Shopee',
        MarketplaceAccount::CHANNEL_MERCADO_LIVRE => 'Mercado Livre',
        MarketplaceAccount::CHANNEL_TIKTOK_SHOP => 'TikTok Shop',
        MarketplaceAccount::CHANNEL_AMAZON => 'Amazon',
    ];

    // Canais mostrados no card "pedidos por canal" — Shein deliberadamente
    // fora (pedido explícito do usuário), loja própria também não entra
    // aqui (esse card é só sobre marketplace).
    private const CHANNEL_QUEUE_ORDER = [
        MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
        MarketplaceAccount::CHANNEL_SHOPEE,
        MarketplaceAccount::CHANNEL_TIKTOK_SHOP,
        MarketplaceAccount::CHANNEL_AMAZON,
    ];

    private const CHANNEL_ICONS = [
        MarketplaceAccount::CHANNEL_SHOPEE => 'fas fa-bag-shopping',
        MarketplaceAccount::CHANNEL_MERCADO_LIVRE => 'fas fa-store',
        MarketplaceAccount::CHANNEL_TIKTOK_SHOP => 'fab fa-tiktok',
        MarketplaceAccount::CHANNEL_AMAZON => 'fab fa-amazon',
    ];

    /**
     * Mapeamento status -> cor pedido pelo usuário. Sem um status
     * "concluído" separado de "impresso" no PrintJob, PRINTED cobre os
     * dois sentidos (sucesso final); os outros três encaixam de forma
     * direta no ciclo de vida real do job.
     */
    private const STATUS_META = [
        PrintJob::STATUS_QUEUED => ['label' => 'Na fila', 'description' => 'Aguardando o KoraSync buscar e imprimir.', 'icon' => 'fas fa-hourglass-half', 'color' => 'yellow'],
        PrintJob::STATUS_CLAIMED => ['label' => 'Imprimindo', 'description' => 'Capturado por um agente e sendo processado agora.', 'icon' => 'fas fa-print', 'color' => 'purple'],
        PrintJob::STATUS_PRINTED => ['label' => 'Concluída', 'description' => 'Etiqueta impressa com sucesso.', 'icon' => 'fas fa-circle-check', 'color' => 'green'],
        PrintJob::STATUS_FAILED => ['label' => 'Falhou', 'description' => 'Erro ao processar ou imprimir a etiqueta.', 'icon' => 'fas fa-triangle-exclamation', 'color' => 'red'],
    ];

    /** Mesma definição de "venda confirmada" que o DashboardController usa pro faturamento. */
    private const PAID_STATUSES = [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED];

    public function index(): Response
    {
        $today = Carbon::today();
        $startOfMonth = Carbon::today()->startOfMonth();

        $channelCounts = Order::query()
            ->whereIn('origin', self::CHANNEL_QUEUE_ORDER)
            ->selectRaw('origin, count(*) as total')
            ->groupBy('origin')
            ->pluck('total', 'origin');

        // Fila de expedição: pedido pago que ainda não foi enviado — assim
        // que sai de "paid" (enviado/cancelado), some da tela, porque já
        // foi embalado (ou não vai mais ser). packed_at (botão "Em
        // preparação" do KoraSync, ver DashboardAgentController::
        // packOrder()) NÃO filtra aqui nem lá — pedido explícito
        // 2026-08-13: o operador quer continuar vendo a lista inteira do
        // dia como conferência visual, o botão só muda de cor/texto pra
        // "Embalado", nunca some da tela sozinho. Ordem decrescente = mais
        // recente primeiro, igual pedido do usuário. orderByDesc('id') em
        // vez de latest()/created_at: dois pedidos pagos no mesmo segundo
        // (granularidade do datetime do MySQL) empatam em created_at e
        // ORDER BY created_at DESC sozinho não garante desempate — bug real
        // encontrado 2026-08-06 escrevendo o endpoint equivalente do
        // KoraSync nativo (PrintJobControllerTest::dispatch_queue já
        // reproduzia isso, só nunca tinha sido notado). id crescente é
        // ordem cronológica exata, sem empate possível.
        $queue = Order::query()
            ->where('status', Order::STATUS_PAID)
            ->with('items:id,order_id,product_name,quantity')
            ->withSum('items as units_count', 'quantity')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Order $order) => [
                'id' => $order->id,
                'externalOrderId' => $order->external_order_id,
                'channel' => self::CHANNEL_LABELS[$order->origin] ?? ($order->origin === Order::ORIGIN_STORE ? 'Site' : $order->origin),
                'channelIcon' => self::CHANNEL_ICONS[$order->origin] ?? 'fas fa-shop',
                'customer' => $order->shipping_name,
                'unitsCount' => (int) $order->units_count,
                'products' => $order->items->map(fn ($item) => $item->quantity > 1
                    ? "{$item->quantity}x {$item->product_name}"
                    : $item->product_name)->all(),
                'total' => (float) $order->total,
                'createdAt' => $order->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
            ]);

        return Inertia::render('Admin/Impressoes/Index', [
            'stats' => [
                // subtotal, não total — 'total' inclui frete (não é receita
                // do vendedor), ver comentário em
                // FinancialDashboardController::index().
                'revenueMonth' => (float) Order::query()->whereIn('status', self::PAID_STATUSES)->where('created_at', '>=', $startOfMonth)->sum('subtotal'),
                'revenueToday' => (float) Order::query()->whereIn('status', self::PAID_STATUSES)->whereDate('created_at', $today)->sum('subtotal'),
                'ordersTotal' => Order::query()->count(),
            ],
            'channelCounts' => collect(self::CHANNEL_QUEUE_ORDER)->map(fn ($channel) => [
                'channel' => $channel,
                'label' => self::CHANNEL_LABELS[$channel],
                'icon' => self::CHANNEL_ICONS[$channel],
                'total' => (int) ($channelCounts->get($channel) ?? 0),
            ])->values(),
            'queue' => $queue,
        ]);
    }

    public function list(Request $request): Response
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(self::STATUS_META))],
        ]);

        $jobs = PrintJob::query()
            ->with('order:id,origin,external_order_id,shipping_name')
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (PrintJob $job) => [
                'id' => $job->id,
                'status' => $job->status,
                'statusLabel' => self::STATUS_META[$job->status]['label'] ?? $job->status,
                'statusColor' => self::STATUS_META[$job->status]['color'] ?? 'slate',
                'channel' => self::CHANNEL_LABELS[$job->order?->origin] ?? $job->order?->origin,
                'channelIcon' => self::CHANNEL_ICONS[$job->order?->origin] ?? 'fas fa-shop',
                'orderId' => $job->order_id,
                'saleId' => $job->order?->external_order_id,
                'shippingName' => $job->order?->shipping_name,
                'claimedBy' => $job->claimed_by,
                'errorMessage' => $job->error_message,
                'createdAt' => $job->created_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
                'claimedAt' => $job->claimed_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
                'printedAt' => $job->printed_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
            ]);

        return Inertia::render('Admin/Impressoes/Listar', [
            'jobs' => $jobs,
            'statusFilter' => $validated['status'] ?? null,
            'statusOptions' => collect(self::STATUS_META)->map(fn ($meta, $status) => ['value' => $status, 'label' => $meta['label']])->values(),
        ]);
    }

    public function show(PrintJob $printJob): Response
    {
        $printJob->load('order:id,origin,external_order_id,shipping_name');

        return Inertia::render('Admin/Impressoes/Show', [
            'job' => [
                'id' => $printJob->id,
                'orderId' => $printJob->order_id,
                'saleId' => $printJob->order?->external_order_id,
                'shippingName' => $printJob->order?->shipping_name,
                'channel' => self::CHANNEL_LABELS[$printJob->order?->origin] ?? $printJob->order?->origin,
                'channelIcon' => self::CHANNEL_ICONS[$printJob->order?->origin] ?? 'fas fa-shop',
                'status' => $printJob->status,
                'statusLabel' => self::STATUS_META[$printJob->status]['label'] ?? $printJob->status,
                'statusColor' => self::STATUS_META[$printJob->status]['color'] ?? 'slate',
                'claimedBy' => $printJob->claimed_by,
                'errorMessage' => $printJob->error_message,
                'createdAt' => $printJob->created_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s'),
                'claimedAt' => $printJob->claimed_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s'),
                'printedAt' => $printJob->printed_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s'),
                'hasLabelFile' => (bool) ($printJob->label_path && Storage::disk('local')->exists($printJob->label_path)),
            ],
        ]);
    }

    /**
     * Mesmo disco/Content-Type que ManualLabelController::pdf() — só que
     * sem o `abort_if(order_id !== null)` de lá, porque aqui é justamente o
     * job real do pipeline automático, com pedido associado.
     */
    public function pdf(PrintJob $printJob): HttpResponse
    {
        abort_unless($printJob->label_path && Storage::disk('local')->exists($printJob->label_path), 404, 'Arquivo da etiqueta não encontrado.');

        return response(Storage::disk('local')->get($printJob->label_path), 200, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function destroy(PrintJob $printJob): RedirectResponse
    {
        if ($printJob->label_path && Storage::disk('local')->exists($printJob->label_path)) {
            Storage::disk('local')->delete($printJob->label_path);
        }

        $printJob->delete();

        return back()->with('success', "Impressão #{$printJob->id} removida.");
    }
}
