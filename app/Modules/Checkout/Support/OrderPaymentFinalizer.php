<?php

namespace App\Modules\Checkout\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockManager;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Services\Stripe\StripePaymentService;

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
        private readonly MercadoPagoPaymentService $mercadoPago,
        private readonly StockManager $stock,
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
                $payment->provider === Payment::PROVIDER_MERCADOPAGO
                    ? $this->mercadoPago->captureOrder($payment->mercadopago_order_id)
                    : $this->stripe->capture($payment->stripe_payment_intent_id);
                $payment->update(['status' => Payment::STATUS_CAPTURED]);
            }
        }

        $order->update(['status' => Order::STATUS_PAID]);

        // Emissão da nota + envio do e-mail de recibo acontecem de forma
        // assíncrona, numa fila — o webhook do Stripe (chamador mais comum
        // deste método) precisa responder rápido, sem esperar SEFAZ/SMTP.
        // GenerateInvoiceJob dispara o e-mail sozinho ao final (sucesso,
        // rejeição definitiva ou falha esgotada) — ver App\Modules\Fiscal\Jobs.
        GenerateInvoiceJob::dispatch($order->id);

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
                $payment->provider === Payment::PROVIDER_MERCADOPAGO
                    ? $this->mercadoPago->cancelOrder($payment->mercadopago_order_id)
                    : $this->stripe->cancel($payment->stripe_payment_intent_id);
                $payment->update(['status' => Payment::STATUS_CANCELED]);
            }
        }

        if ($order->status !== Order::STATUS_PAID) {
            $order->update(['status' => Order::STATUS_PENDING]);
            $this->restoreStockIfNeeded($order, 'Pagamento não concluído');
        }
    }

    /**
     * O estoque é debitado na hora que o pedido é criado (antes do
     * pagamento confirmar — reserva otimista, evita vender a mesma unidade
     * duas vezes enquanto um pagamento está em andamento), mas nada
     * devolvia esse estoque se o pagamento falhasse/expirasse/fosse
     * cancelado — bug real, encontrado ao construir a sincronização
     * multi-canal (um carrinho abandonado no site "sumia" estoque que
     * continuava disponível nos outros canais). `stock_restored_at` evita
     * devolver duas vezes o mesmo pedido.
     */
    public function restoreStockIfNeeded(Order $order, string $reason): void
    {
        if ($order->stock_restored_at) {
            return;
        }

        $order->loadMissing('items.product');

        foreach ($order->items as $item) {
            if (! $item->product) {
                continue;
            }

            $this->stock->adjust($item->product, $item->quantity, StockMovement::TYPE_RETURN, reason: $reason, reference: $order);
        }

        $order->update(['stock_restored_at' => now()]);
    }

    /**
     * Disparado quando o admin cancela um pedido (OrderController::update).
     * Pagamento capturado (dinheiro já saiu de verdade) → reembolso real via
     * API; pagamento só autorizado (nunca capturado) → apenas cancela, sem
     * reembolso, porque o dinheiro nunca chegou a sair. Nunca lança — cada
     * falha vira uma mensagem na lista de retorno, pra não travar o
     * cancelamento do pedido em si por causa de um problema no Stripe.
     *
     * @return array<int, string>
     */
    public function refundOrder(Order $order): array
    {
        $order->loadMissing('payments');
        $errors = [];

        foreach ($order->payments as $payment) {
            $isMercadoPago = $payment->provider === Payment::PROVIDER_MERCADOPAGO;
            // Pix (Mercado Pago) é sempre a API de Payments clássica, sem
            // mercadopago_order_id — cartão (Mercado Pago) é sempre a API
            // de Orders. Ver MercadoPagoPaymentService.
            $isMercadoPagoOrder = $isMercadoPago && $payment->mercadopago_order_id;
            $reference = match (true) {
                $isMercadoPagoOrder => $payment->mercadopago_order_id,
                $isMercadoPago => $payment->mercadopago_payment_id,
                default => $payment->stripe_payment_intent_id,
            };

            try {
                if ($payment->status === Payment::STATUS_CAPTURED) {
                    match (true) {
                        $isMercadoPagoOrder => $this->mercadoPago->refundOrder($reference),
                        $isMercadoPago => $this->mercadoPago->refundPayment($reference),
                        default => $this->stripe->refund($reference),
                    };
                    $payment->update(['status' => Payment::STATUS_REFUNDED]);
                } elseif ($payment->status === Payment::STATUS_AUTHORIZED) {
                    match (true) {
                        $isMercadoPagoOrder => $this->mercadoPago->cancelOrder($reference),
                        $isMercadoPago => $this->mercadoPago->cancelPayment($reference),
                        default => $this->stripe->cancel($reference),
                    };
                    $payment->update(['status' => Payment::STATUS_CANCELED]);
                }
            } catch (\Throwable $exception) {
                $errors[] = "Pagamento {$reference}: {$exception->getMessage()}";
            }
        }

        return $errors;
    }
}
