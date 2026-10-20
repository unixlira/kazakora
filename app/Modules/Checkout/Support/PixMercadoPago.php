<?php

namespace App\Modules\Checkout\Support;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Notifications\PagamentoEmPedidoCanceladoNotification;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Confirmação do Pix fora da tela do checkout (achado real 19/10, #3283:
 * pago às 21:14 com o navegador do cliente em segundo plano, reconhecido só
 * às 21:40). Usado pelo webhook do tópico "payment" e pela varredura
 * `mercadopago:conferir-pix` (a cada minuto). A fonte da verdade é sempre
 * GET /v1/payments/{id}, nunca o corpo do aviso.
 */
class PixMercadoPago
{
    public const PAGO = 'pago';

    public const RECUSADO = 'recusado';

    public const PENDENTE = 'pendente';

    public function __construct(
        private readonly MercadoPagoPaymentService $mercadoPago,
        private readonly OrderPaymentFinalizer $finalizer,
    ) {
    }

    public function sincronizar(Payment $payment): string
    {
        if (! $payment->pixPendente()) {
            return self::PENDENTE;
        }

        $mp = $this->mercadoPago->retrievePayment((string) $payment->mercadopago_payment_id);
        $status = $mp['status'] ?? null;

        if ($status === 'approved') {
            // Trava: tela, webhook e varredura podem chegar juntos; só quem
            // vira o status de "aguardando" para "capturado" segue.
            $virou = Payment::query()->whereKey($payment->id)
                ->where('status', Payment::STATUS_REQUIRES_CONFIRMATION)
                ->update(['status' => Payment::STATUS_CAPTURED]);

            if ($virou === 1) {
                $this->confirmar($payment->fresh());
            }

            return self::PAGO;
        }

        if (in_array($status, ['cancelled', 'rejected'], true)) {
            // Mesmo caminho do polling da tela (CheckoutController::status).
            $payment->recusar($mp);
            $order = $payment->order()->with('payments')->first();
            if ($order) {
                $this->finalizer->cancelSiblingsAfterFailure($order, $payment);
            }

            return self::RECUSADO;
        }

        return self::PENDENTE;
    }

    private function confirmar(Payment $payment): void
    {
        $order = $payment->order()->with('payments')->first();

        if (! $order) {
            return;
        }

        if ($order->status === Order::STATUS_CANCELLED) {
            self::avisarPagoComPedidoCancelado($order, $payment);

            return;
        }

        $this->finalizer->finalize($order);
    }

    /**
     * Pago com o pedido já cancelado: não ressuscita (o estoque já voltou)
     * nem devolve sozinho (é dinheiro). Log + sino dos admins.
     */
    public static function avisarPagoComPedidoCancelado(Order $order, Payment $payment): void
    {
        Log::channel('mercadopago')->critical('mercadopago.pago_com_pedido_cancelado', [
            'order_id' => $order->id,
            'payment_id' => $payment->mercadopago_payment_id,
            'method' => $payment->method_type,
        ]);

        $admins = User::query()->where('role', User::ROLE_ADMIN)->get();
        if ($admins->isNotEmpty()) {
            rescue(fn () => Notification::send($admins, new PagamentoEmPedidoCanceladoNotification($order, $payment)));
        }
    }
}
