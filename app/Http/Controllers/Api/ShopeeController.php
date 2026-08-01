<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessShopeeWebhook;
use App\Modules\Marketplace\Models\ChannelWebhookLog;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Services\Shopee\Exceptions\ShopeeException;
use App\Services\Shopee\ShopeeAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ShopeeController extends Controller
{
    public function __construct(private readonly ShopeeAuthService $auth) {}

    public function redirectToAuth(): RedirectResponse
    {
        return redirect()->away($this->auth->getAuthorizationUrl());
    }

    public function callback(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
            'shop_id' => ['required', 'integer'],
        ]);

        try {
            $this->auth->handleCallback($validated['code'], (int) $validated['shop_id']);
        } catch (ShopeeException $exception) {
            return redirect('/admin/empresa')->with('error', $exception->getMessage());
        }

        return redirect('/admin/empresa')->with('success', 'Loja da Shopee conectada com sucesso.');
    }

    /**
     * Push Notification da Shopee — precisa responder 200 rápido (a Shopee
     * bloqueia/desativa o endpoint se ele não responder logo), então só
     * valida a assinatura + grava o log aqui e devolve na hora; o
     * processamento de verdade (importar o pedido etc.) vai pra fila.
     *
     * Assinatura: HMAC-SHA256("{push_url}|{corpo_cru}", partner_key), no
     * header Authorization (string hex crua, sem prefixo "Bearer") —
     * mecanismo confirmado contra a implementação de referência da
     * comunidade (open.shopee.com ficou inacessível pra verificação direta
     * nesta sessão, ver plano). Assinatura inválida = rejeita e loga, nunca
     * processa.
     */
    public function webhook(Request $request): JsonResponse
    {
        $rawBody = $request->getContent();
        $payload = json_decode($rawBody, true) ?? [];
        $authorizationHeader = $request->header('Authorization', '');

        $signatureValid = $this->hasValidSignature($rawBody, $authorizationHeader);

        $log = ChannelWebhookLog::create([
            'channel' => MarketplaceAccount::CHANNEL_SHOPEE,
            'event_type' => $payload['code'] ?? null,
            'payload' => $payload,
            'headers' => $request->headers->all(),
            'signature_valid' => $signatureValid,
            'status' => $signatureValid ? ChannelWebhookLog::STATUS_RECEIVED : ChannelWebhookLog::STATUS_REJECTED,
        ]);

        if (! $signatureValid) {
            Log::channel('shopee')->warning('shopee.webhook.invalid_signature', ['log_id' => $log->id]);

            return response()->json(['error' => 'invalid_signature'], 401);
        }

        Log::channel('shopee')->info('shopee.webhook.received', $payload);

        ProcessShopeeWebhook::dispatch($payload, $log->id);

        return response()->json(['status' => 'received']);
    }

    private function hasValidSignature(string $rawBody, string $authorizationHeader): bool
    {
        $pushUrl = config('services.shopee.push_url');
        $pushPartnerKey = config('services.shopee.push_partner_key');

        if (! $pushUrl || ! $pushPartnerKey || $authorizationHeader === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $pushUrl.'|'.$rawBody, $pushPartnerKey);

        return hash_equals($expected, $authorizationHeader);
    }
}
