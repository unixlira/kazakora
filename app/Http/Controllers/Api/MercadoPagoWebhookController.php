<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Checkout\Support\OrderPaymentFinalizer;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class MercadoPagoWebhookController extends Controller
{
    public function __construct(
        private readonly MercadoPagoPaymentService $mercadoPago,
        private readonly OrderPaymentFinalizer $finalizer,
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        if (! $this->hasValidSignature($request)) {
            Log::channel('mercadopago')->warning('mercadopago.webhook.invalid_signature');

            return response()->json(['error' => 'invalid_signature'], 400);
        }

        $type = $request->input('type');
        $resourceId = $request->input('data.id') ?? $request->query('data_id');

        Log::channel('mercadopago')->info('mercadopago.webhook.received', ['type' => $type, 'resource_id' => $resourceId]);

        // A aplicação só está inscrita nos tópicos order/fraude/contestação
        // (não "payment") — fraude e contestação só são reconhecidos e
        // logados por enquanto, sem lógica de negócio ainda.
        if ($type !== 'order' || ! $resourceId) {
            return response()->json(['status' => 'ignored']);
        }

        $payment = Payment::query()
            ->where('provider', Payment::PROVIDER_MERCADOPAGO)
            ->where('mercadopago_order_id', (string) $resourceId)
            ->first();

        if (! $payment) {
            return response()->json(['status' => 'ignored']);
        }

        try {
            $mpOrder = $this->mercadoPago->retrieveOrder((string) $resourceId);
        } catch (Throwable $exception) {
            Log::channel('mercadopago')->error('mercadopago.webhook.retrieve_failed', [
                'order_id' => $resourceId,
                'message' => $exception->getMessage(),
            ]);

            return response()->json(['status' => 'error'], 500);
        }

        $mpStatus = $mpOrder['transactions']['payments'][0]['status'] ?? null;

        match ($mpStatus) {
            'approved' => $this->handleSuccess($payment),
            'cancelled', 'rejected' => $this->handleFailure($payment),
            default => null,
        };

        return response()->json(['status' => 'received']);
    }

    private function handleSuccess(Payment $payment): void
    {
        // Pix aprovado já é dinheiro do vendedor — vai direto pra captured
        // (mesma regra do polling em CheckoutController::status(), sem fase
        // intermediária "autorizado sem capturar" como o cartão no Stripe).
        if (in_array($payment->status, [Payment::STATUS_AUTHORIZED, Payment::STATUS_CAPTURED], true)) {
            return;
        }

        $payment->update(['status' => Payment::STATUS_CAPTURED]);
        $payment->load('order.payments');

        $this->finalizer->finalize($payment->order);
    }

    private function handleFailure(Payment $payment): void
    {
        if ($payment->status === Payment::STATUS_CANCELED) {
            return;
        }

        $payment->update(['status' => Payment::STATUS_CANCELED]);
        $payment->load('order.payments');

        $this->finalizer->cancelSiblingsAfterFailure($payment->order, $payment);
    }

    /**
     * Assinatura HMAC do Mercado Pago (header x-signature: ts=...,v1=...).
     * Algoritmo documentado deles: manifest "id:{data.id};request-id:{x-request-id};ts:{ts};"
     * assinado com o webhook secret do painel — id vem da query string
     * (data.id), não do corpo: o PHP converte pontos em nomes de parâmetro de
     * query string pra underscore automaticamente (data.id vira data_id em
     * $_GET), então ler do corpo aqui daria um manifest errado mesmo com o
     * secret certo.
     */
    private function hasValidSignature(Request $request): bool
    {
        $secret = config('services.mercadopago.webhook_secret');

        if (! $secret) {
            Log::channel('mercadopago')->warning('mercadopago.webhook.no_secret_configured');

            return true;
        }

        $signatureHeader = $request->header('x-signature', '');
        $requestId = $request->header('x-request-id', '');
        $dataId = $request->query('data_id');

        $parts = [];
        foreach (explode(',', $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, null);
            $parts[trim((string) $key)] = trim((string) $value);
        }

        $ts = $parts['ts'] ?? null;
        $v1 = $parts['v1'] ?? null;

        if (! $ts || ! $v1 || ! $dataId) {
            return false;
        }

        $manifest = 'id:'.strtolower($dataId).";request-id:{$requestId};ts:{$ts};";
        $expected = hash_hmac('sha256', $manifest, $secret);

        return hash_equals($expected, $v1);
    }
}
