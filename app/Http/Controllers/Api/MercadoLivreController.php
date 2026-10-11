<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMercadoLivreWebhook;
use App\Modules\Marketplace\Jobs\PokeMercadoLivreLabelChecksJob;
use App\Modules\Marketplace\Models\ChannelWebhookLog;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Services\MercadoLivre\Exceptions\MercadoLivreException;
use App\Services\MercadoLivre\MercadoLivreAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MercadoLivreController extends Controller
{
    public function __construct(private readonly MercadoLivreAuthService $auth) {}

    public function redirectToAuth(): RedirectResponse
    {
        return redirect()->away($this->auth->getAuthorizationUrl());
    }

    public function callback(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
        ]);

        try {
            $token = $this->auth->handleCallback($validated['code'], $validated['state']);
        } catch (MercadoLivreException $exception) {
            return redirect('/admin/empresa')->with('error', $exception->getMessage());
        }

        return redirect('/admin/empresa')->with(
            'success',
            "Conta do Mercado Livre \"{$token->ml_nickname}\" conectada com sucesso.",
        );
    }

    /**
     * Igual ao padrão já usado no ShopeeController::webhook() —
     * ChannelWebhookLog dá visibilidade real (consultável, ver
     * WebhookLogController) de "recebemos o webhook ou não", em vez de só
     * um log de arquivo que só dá pra ver via SSH. Mercado Livre não tem
     * mecanismo de assinatura documentado/implementado aqui (diferente da
     * Shopee, HMAC) — signature_valid fica sempre true, só existe pra
     * manter o schema consistente entre canais.
     */
    public function webhook(Request $request): JsonResponse
    {
        $payload = $request->all();

        Log::channel(config('mercadolivre.log_channel'))->info('mercadolivre.webhook.raw', $payload);

        $log = ChannelWebhookLog::create([
            'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'event_type' => $payload['topic'] ?? null,
            'payload' => $payload,
            'headers' => $request->headers->all(),
            'signature_valid' => true,
            'status' => ChannelWebhookLog::STATUS_RECEIVED,
        ]);

        // Acknowledge immediately — Mercado Livre expects a fast 200 and
        // retries/blocks the app's webhook URL if it doesn't get one.
        ProcessMercadoLivreWebhook::dispatch($payload, $log->id);

        // Pedido explícito do usuário 2026-08-05: qualquer webhook do
        // Mercado Livre (não só orders_v2/shipments) serve de "empurrão"
        // pra reconferir etiquetas pendentes — em vez de depender só do
        // cron de polling. Fica de fora do topic-filter do WebhookHandler
        // de propósito (aquele ignora tópicos não tratados; este dispara
        // pra QUALQUER um, igual pedido). Só mais um push de fila, não
        // atrasa o ack — mesmo custo do dispatch acima.
        PokeMercadoLivreLabelChecksJob::dispatch();

        return response()->json(['status' => 'received']);
    }
}
