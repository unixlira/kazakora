<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\PrintJob;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Monitoramento dos PrintJobs consumidos pelo KoraSync (agente nativo de
 * impressão) — só leitura, não interfere no fluxo real de impressão.
 */
class PrintJobController extends Controller
{
    private const CHANNEL_LABELS = [
        MarketplaceAccount::CHANNEL_SHOPEE => 'Shopee',
        MarketplaceAccount::CHANNEL_MERCADO_LIVRE => 'Mercado Livre',
        MarketplaceAccount::CHANNEL_TIKTOK_SHOP => 'TikTok Shop',
        MarketplaceAccount::CHANNEL_AMAZON => 'Amazon',
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

    public function index(): Response
    {
        $counts = PrintJob::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $cards = collect(self::STATUS_META)->map(fn ($meta, $status) => [
            'status' => $status,
            ...$meta,
            'total' => (int) $counts->get($status, 0),
        ])->values();

        return Inertia::render('Admin/Impressoes/Index', [
            'cards' => $cards,
            'totalGeral' => (int) $counts->sum(),
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
}
