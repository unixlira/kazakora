<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Checkout\Support\BoletoMercadoPago;
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
        // Boleto (2026-10-14): se o tópico "payment" for ligado no painel, o
        // boleto pago confirma na hora em vez de esperar a varredura de 30 min.
        // Pix (auditoria 2026-10-15): o mesmo tópico confirma o Pix pago com a
        // aba do checkout já fechada.
        if ($type === 'payment' && $resourceId) {
            return $this->handlePaymentTopic((string) $resourceId);
        }

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

        // Vocabulário real da API de Orders (confirmado direto na API, não
        // documentação): "processed" é sucesso, "failed"/"rejected"/
        // "cancelled" são falha. "approved" (API de Payments antiga) não
        // existe mais aqui.
        $mpPayment = $mpOrder['transactions']['payments'][0] ?? [];
        $mpStatus = $mpPayment['status'] ?? null;

        match (true) {
            $mpStatus === 'processed' => $this->handleSuccess($payment),
            in_array($mpStatus, ['failed', 'rejected', 'cancelled'], true) => $this->handleFailure($payment, $mpPayment),
            default => null,
        };

        return response()->json(['status' => 'received']);
    }

    private function handlePaymentTopic(string $paymentId): JsonResponse
    {
        $payment = Payment::query()
            ->where('provider', Payment::PROVIDER_MERCADOPAGO)
            ->whereIn('method_type', [Payment::METHOD_BOLETO, Payment::METHOD_PIX])
            ->where('mercadopago_payment_id', $paymentId)
            ->first();

        if (! $payment) {
            return response()->json(['status' => 'ignored']);
        }

        try {
            $payment->method_type === Payment::METHOD_BOLETO
                ? app(BoletoMercadoPago::class)->sincronizar($payment)
                : $this->syncPix($payment);
        } catch (Throwable $exception) {
            Log::channel('mercadopago')->error('mercadopago.webhook.payment_failed', [
                'payment_id' => $paymentId,
                'message' => $exception->getMessage(),
            ]);

            return response()->json(['status' => 'error'], 500);
        }

        return response()->json(['status' => 'received']);
    }

    private function syncPix(Payment $payment): void
    {
        if ($payment->status !== Payment::STATUS_REQUIRES_CONFIRMATION) {
            return;
        }

        $status = $this->mercadoPago->retrievePayment((string) $payment->mercadopago_payment_id)['status'] ?? null;

        if ($status !== 'approved') {
            return;
        }

        $payment->update(['status' => Payment::STATUS_CAPTURED]);
        $order = $payment->order()->with('payments')->first();

        if ($order?->status === Order::STATUS_CANCELLED) {
            // Mesma regra do boleto: não ressuscita pedido cancelado (o
            // estoque já voltou). Fica no log para devolver ou refazer à mão.
            Log::channel('mercadopago')->critical('mercadopago.pix.pago_com_pedido_cancelado', [
                'order_id' => $order->id,
                'payment_id' => $payment->mercadopago_payment_id,
            ]);

            return;
        }

        if ($order) {
            $this->finalizer->finalize($order);
        }
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

    private function handleFailure(Payment $payment, array $mpPayment): void
    {
        if ($payment->status === Payment::STATUS_CANCELED) {
            return;
        }

        $payment->recusar($mpPayment);
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
