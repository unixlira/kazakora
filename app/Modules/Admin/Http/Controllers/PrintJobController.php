<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\PrintJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Storage;
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
