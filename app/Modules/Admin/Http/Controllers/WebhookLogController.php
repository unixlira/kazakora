<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Models\ChannelWebhookLog;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Visibilidade real de "recebemos o webhook ou não" de cada marketplace —
 * antes só dava pra ver via log de arquivo (SSH). Read-only, não altera
 * nada — ChannelWebhookLog é escrito pelos controllers de webhook de cada
 * canal (MercadoLivreController, ShopeeController) e atualizado pelos
 * respectivos WebhookHandler conforme o processamento avança.
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
}
