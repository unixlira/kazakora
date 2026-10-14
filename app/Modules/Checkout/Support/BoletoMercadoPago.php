<?php

namespace App\Modules\Checkout\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use Illuminate\Support\Facades\Log;

/**
 * Confirmação do boleto do Mercado Pago (pedido 2026-10-14). O boleto é pago
 * dias depois, longe da tela do checkout, então quem confere é a varredura
 * `mercadopago:conferir-boletos` (a cada 30 min) e o webhook do tópico
 * "payment", se um dia for ligado no painel. A fonte da verdade é sempre o
 * status do pagamento no Mercado Pago (GET /v1/payments/{id}):
 *
 * - approved → pagamento capturado e pedido pago (nota, WhatsApp, e-mail:
 *   o mesmo OrderPaymentFinalizer do Pix e do cartão);
 * - cancelled/rejected (o MP cancela sozinho o boleto vencido) → pedido
 *   cancelado e estoque devolvido, como no Pix expirado;
 * - ainda pendente muito depois do vencimento (loja.boleto_cancelar_apos_dias)
 *   → a loja cancela o boleto no MP e o pedido.
 */
class BoletoMercadoPago
{
    public const PAGO = 'pago';

    public const CANCELADO = 'cancelado';

    public const PENDENTE = 'pendente';

    public function __construct(
        private readonly MercadoPagoPaymentService $mercadoPago,
        private readonly OrderPaymentFinalizer $finalizer,
    ) {
    }

    public function sincronizar(Payment $payment): string
    {
        if (! $payment->boletoPendente() || ! $payment->mercadopago_payment_id) {
            return self::PENDENTE;
        }

        $mp = $this->mercadoPago->retrievePayment($payment->mercadopago_payment_id);
        $status = $mp['status'] ?? null;

        if ($status === 'approved') {
            return $this->confirmar($payment);
        }

        if (in_array($status, ['cancelled', 'rejected', 'refunded', 'charged_back'], true)) {
            return $this->cancelar($payment, 'Boleto não pago (Mercado Pago: '.$status.')');
        }

        if ($this->passouDoPrazo($payment)) {
            // Ainda "pendente" bem depois do vencimento e da compensação:
            // cancela no Mercado Pago antes, para ninguém pagar um pedido
            // que já não existe. Falhou o cancelamento lá? Não mexe aqui.
            $this->mercadoPago->cancelPayment($payment->mercadopago_payment_id);

            return $this->cancelar($payment, 'Boleto vencido sem pagamento');
        }

        return self::PENDENTE;
    }

    private function confirmar(Payment $payment): string
    {
        $payment->update(['status' => Payment::STATUS_CAPTURED]);
        $order = $payment->order()->with('payments')->first();

        if (! $order) {
            return self::PAGO;
        }

        if ($order->status === Order::STATUS_CANCELLED) {
            // Pago depois de o pedido já ter sido cancelado por outro motivo:
            // não ressuscita o pedido sozinho (o estoque já voltou). Fica no
            // log para devolver o dinheiro ou refazer o pedido à mão.
            Log::channel('mercadopago')->critical('mercadopago.boleto.pago_com_pedido_cancelado', [
                'order_id' => $order->id,
                'payment_id' => $payment->mercadopago_payment_id,
            ]);

            return self::PAGO;
        }

        $this->finalizer->finalize($order);

        return self::PAGO;
    }

    private function cancelar(Payment $payment, string $motivo): string
    {
        $payment->update(['status' => Payment::STATUS_CANCELED]);
        $order = $payment->order()->first();

        if ($order && in_array($order->status, [Order::STATUS_AWAITING_PAYMENT, Order::STATUS_PENDING], true)) {
            $order->update(['status' => Order::STATUS_CANCELLED]);
            $this->finalizer->restoreStockIfNeeded($order, $motivo);
        }

        return self::CANCELADO;
    }

    private function passouDoPrazo(Payment $payment): bool
    {
        $vencimento = $payment->expira_em ?? $payment->created_at?->copy()->addDays((int) config('loja.boleto_dias_vencimento', 3));

        return $vencimento !== null
            && $vencimento->copy()->addDays((int) config('loja.boleto_cancelar_apos_dias', 7))->isPast();
    }
}
