<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Models\ChannelWebhookLog;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Services\MercadoLivre\Webhooks\WebhookHandler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Visibilidade real de "recebemos o webhook ou não" de cada marketplace —
 * antes só dava pra ver via log de arquivo (SSH). index() é read-only, não
 * altera nada. reprocess() é a exceção deliberada: existe especificamente
 * pra webhooks que ficaram "ignorado" antes de um tópico passar a ser
 * tratado (caso real: post_purchase virou suportado só em 2026-08-05,
 * vários chegaram e ficaram ignorados nas horas antes do deploy) — reroda
 * o mesmo payload já salvo contra o WebhookHandler atual, sem precisar o
 * Mercado Livre reenviar nada.
 */
class WebhookLogController extends Controller
{
    private const CHANNELS = [
        MarketplaceAccount::CHANNEL_MERCADO_LIVRE => 'Mercado Livre',
        MarketplaceAccount::CHANNEL_SHOPEE => 'Shopee',
        MarketplaceAccount::CHANNEL_TIKTOK_SHOP => 'TikTok Shop',
        MarketplaceAccount::CHANNEL_AMAZON => 'Amazon',
        MarketplaceAccount::CHANNEL_SHEIN => 'Shein',
    ];

    public function index(Request $request): Response
    {
        $logs = ChannelWebhookLog::query()
            ->when($request->filled('channel'), fn ($query) => $query->where('channel', $request->string('channel')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (ChannelWebhookLog $log) => [
                'id' => $log->id,
                'channel' => self::CHANNELS[$log->channel] ?? $log->channel,
                'eventType' => $log->event_type,
                'status' => $log->status,
                'signatureValid' => $log->signature_valid,
                'errorMessage' => $log->error_message,
                'payload' => $log->payload,
                'createdAt' => $log->created_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s'),
            ]);

        return Inertia::render('Admin/Integracoes/WebhookLogs', [
            'logs' => $logs,
            'channels' => collect(self::CHANNELS)->map(fn ($name, $channel) => ['value' => $channel, 'label' => $name])->values(),
            'statuses' => [
                ['value' => ChannelWebhookLog::STATUS_RECEIVED, 'label' => 'Recebido'],
                ['value' => ChannelWebhookLog::STATUS_PROCESSED, 'label' => 'Processado'],
                ['value' => ChannelWebhookLog::STATUS_IGNORED, 'label' => 'Ignorado'],
                ['value' => ChannelWebhookLog::STATUS_REJECTED, 'label' => 'Rejeitado'],
                ['value' => ChannelWebhookLog::STATUS_FAILED, 'label' => 'Falhou'],
            ],
            'filters' => $request->only('channel', 'status'),
        ]);
    }

    public function reprocess(ChannelWebhookLog $channel_webhook_log, WebhookHandler $handler): RedirectResponse
    {
        if ($channel_webhook_log->channel !== MarketplaceAccount::CHANNEL_MERCADO_LIVRE) {
            return back()->with('error', 'Reprocessamento manual só existe pro Mercado Livre por enquanto.');
        }

        try {
            $handler->handle($channel_webhook_log->payload ?? [], $channel_webhook_log->id);
        } catch (Throwable $exception) {
            return back()->with('error', "Falha ao reprocessar: {$exception->getMessage()}");
        }

        return back()->with('success', 'Webhook reprocessado — confira o status atualizado abaixo.');
    }
}
