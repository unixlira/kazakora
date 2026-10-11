<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Checkout\Support\OrderPaymentFinalizer;
use App\Services\Stripe\StripePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Dispute;
use Stripe\Exception\SignatureVerificationException;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function __construct(
        private readonly StripePaymentService $stripe,
        private readonly OrderPaymentFinalizer $finalizer,
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        try {
            $event = $this->stripe->constructWebhookEvent(
                $request->getContent(),
                $request->header('Stripe-Signature', ''),
            );
        } catch (SignatureVerificationException|UnexpectedValueException $exception) {
            Log::channel('stripe')->warning('stripe.webhook.invalid_signature', ['message' => $exception->getMessage()]);

            return response()->json(['error' => 'invalid_signature'], 400);
        }

        Log::channel('stripe')->info('stripe.webhook.received', ['type' => $event->type, 'id' => $event->id]);

        if ($event->type === 'charge.dispute.created') {
            $this->handleDispute($event->data->object);

            return response()->json(['status' => 'received']);
        }

        $intent = $event->data->object;
        $payment = Payment::query()->where('stripe_payment_intent_id', $intent->id)->first();

        if (! $payment) {
            return response()->json(['status' => 'ignored']);
        }

        match ($event->type) {
            'payment_intent.amount_capturable_updated', 'payment_intent.succeeded' => $this->handleSuccess($payment),
            'payment_intent.payment_failed', 'payment_intent.canceled' => $this->handleFailure($payment),
            default => null,
        };

        return response()->json(['status' => 'received']);
    }

    /**
     * O objeto do evento de disputa é um Dispute, não um PaymentIntent — o
     * vínculo com o nosso Payment/Order é via `dispute->payment_intent`, não
     * `dispute->id`. Não automatiza reembolso/resposta (decisão de negócio,
     * feita manualmente no painel do Stripe) — só registra e sinaliza o pedido.
     */
    private function handleDispute(Dispute $dispute): void
    {
        $payment = Payment::query()->where('stripe_payment_intent_id', $dispute->payment_intent)->first();

        Log::channel('stripe')->warning('stripe.webhook.dispute_created', [
            'dispute_id' => $dispute->id,
            'payment_intent_id' => $dispute->payment_intent,
            'reason' => $dispute->reason,
            'order_id' => $payment?->order_id,
        ]);

        if ($payment) {
            $payment->order()->update(['disputed_at' => now()]);
        }
    }

    private function handleSuccess(Payment $payment): void
    {
        if (in_array($payment->status, [Payment::STATUS_AUTHORIZED, Payment::STATUS_CAPTURED], true)) {
            return;
        }

        $payment->update(['status' => Payment::STATUS_AUTHORIZED]);
        $payment->load('order.payments');

        $this->finalizer->finalize($payment->order);
    }

    private function handleFailure(Payment $payment): void
    {
        if ($payment->status === Payment::STATUS_FAILED) {
            return;
        }

        $payment->update(['status' => Payment::STATUS_FAILED]);
        $payment->load('order.payments');

        $this->finalizer->cancelSiblingsAfterFailure($payment->order, $payment);
    }
}
