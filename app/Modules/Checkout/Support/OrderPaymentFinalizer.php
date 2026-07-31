<?php

namespace App\Modules\Checkout\Support;

use App\Modules\Checkout\Mail\OrderConfirmation;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Fiscal\Services\InvoiceService;
use App\Services\Stripe\StripePaymentService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Coordena o "tudo ou nada" do split de pagamento: só marca o pedido como
 * pago (e captura os cartões com captura manual) quando TODAS as parcelas
 * do pedido estiverem autorizadas/concluídas. Se uma falhar, cancela as
 * outras que já tiverem autorizado (sem capturar), sem tirar dinheiro de
 * ninguém pela metade.
 *
 * Chamado tanto pelo webhook do Stripe (sem sessão do cliente — não limpa
 * o carrinho) quanto pelo endpoint que o navegador do cliente chama depois
 * de confirmar os Payment Elements (esse sim limpa o carrinho da sessão).
 */
class OrderPaymentFinalizer
{
    public function __construct(
        private readonly StripePaymentService $stripe,
        private readonly InvoiceService $invoices,
    ) {
    }

    public function finalize(Order $order): bool
    {
        $order->loadMissing('payments');

        if ($order->status === Order::STATUS_PAID) {
            return true;
        }

        if ($order->payments->isEmpty()) {
            return false;
        }

        $allReady = $order->payments->every(
            fn (Payment $payment) => in_array($payment->status, [Payment::STATUS_AUTHORIZED, Payment::STATUS_CAPTURED], true)
        );

        if (! $allReady) {
            return false;
        }

        foreach ($order->payments as $payment) {
            if ($payment->status === Payment::STATUS_AUTHORIZED) {
                $this->stripe->capture($payment->stripe_payment_intent_id);
                $payment->update(['status' => Payment::STATUS_CAPTURED]);
            }
        }

        $order->update(['status' => Order::STATUS_PAID]);

        // A emissão da NF-e nunca pode travar a confirmação do pagamento —
        // se falhar (ex: certificado A1 ainda não configurado), só registra
        // e segue; o pedido já está pago de verdade de qualquer forma.
        try {
            $this->invoices->issue($order->fresh());
        } catch (Throwable $exception) {
            Log::error('nfe.issue.unexpected_failure', ['order_id' => $order->id, 'message' => $exception->getMessage()]);
        }

        Mail::to($order->user)->send(new OrderConfirmation($order));

        return true;
    }

    public function cancelSiblingsAfterFailure(Order $order, Payment $failedPayment): void
    {
        $order->loadMissing('payments');

        foreach ($order->payments as $payment) {
            if ($payment->id === $failedPayment->id) {
                continue;
            }

            if ($payment->status === Payment::STATUS_AUTHORIZED) {
                $this->stripe->cancel($payment->stripe_payment_intent_id);
                $payment->update(['status' => Payment::STATUS_CANCELED]);
            }
        }

        if ($order->status !== Order::STATUS_PAID) {
            $order->update(['status' => Order::STATUS_PENDING]);
        }
    }
}
