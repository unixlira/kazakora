<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Checkout\Support\OrderPaymentFinalizer;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExpireAbandonedOrders extends Command
{
    protected $signature = 'orders:expire-abandoned';

    protected $description = 'Cancela pedidos parados em aguardando pagamento/pendente há mais de 30 minutos e devolve o estoque';

    /**
     * 30 min: folga confortável acima da janela do Pix (10 min, ver
     * MercadoPagoPaymentService::createPixPayment) e de qualquer checkout
     * de cartão, que é síncrono e nunca fica "preso" por muito tempo.
     */
    private const ABANDONED_AFTER_MINUTES = 30;

    private const LIVRE_PARA_CANCELAR = 'livre';

    private const PAGO = 'pago';

    private const NAO_CONFERIDO = 'nao_conferido';

    public function handle(OrderPaymentFinalizer $finalizer, MercadoPagoPaymentService $mercadoPago): int
    {
        // BUG REAL 2026-08-18 (pedido #476, Shopee 260819PQEFC0TQ, cancelado
        // por engano com estoque restaurado enquanto o pagamento já estava
        // confirmado no canal): esta varredura foi desenhada só pro checkout
        // da própria loja (ver janela do Pix/cartão no comentário de
        // ABANDONED_AFTER_MINUTES acima) mas rodava pra QUALQUER pedido,
        // marketplace incluso. Pedido de canal tem `created_at` forçado pro
        // `placed_at` REAL da venda (ver OrderImportService::createOrder())
        // — não o instante em que o Kazakora recebeu o webhook —, então os
        // 30 min começam a contar antes mesmo do pedido existir aqui. Um
        // atraso normal de confirmação de pagamento no canal (Pix mais
        // lento, fila do ProcessShopeeWebhook, redelivery) é o bastante pra
        // estourar a janela: a varredura cancelava e devolvia estoque de um
        // pedido que segundos depois chegava como "paid"/"READY_TO_SHIP" —
        // aí descartado como "webhook fora de ordem" (OrderImportService::
        // syncStatus()/isStaleStatus()), sem reverter o cancelamento
        // indevido. Pedido de canal nunca fica "esquecido" de verdade: o
        // próprio marketplace manda o status real via webhook/backfill
        // horário (ver orders:sync-shopee), então essa varredura não tem
        // motivo pra existir fora do checkout direto da loja.
        $candidates = Order::query()
            ->where('origin', Order::ORIGIN_STORE)
            ->whereIn('status', [Order::STATUS_AWAITING_PAYMENT, Order::STATUS_PENDING])
            ->where('created_at', '<=', now()->subMinutes(self::ABANDONED_AFTER_MINUTES))
            // Boleto (2026-10-14) fica dias aguardando: quem cancela é a
            // mercadopago:conferir-boletos, pelo status real no Mercado Pago.
            ->whereDoesntHave('payments', fn ($q) => $q
                ->where('method_type', Payment::METHOD_BOLETO)
                ->where('status', Payment::STATUS_REQUIRES_CONFIRMATION))
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('Nenhum pedido abandonado encontrado.');

            return self::SUCCESS;
        }

        foreach ($candidates as $order) {
            // Confirma o status na hora de agir, não só no momento da busca
            // — evita cancelar um pedido que um webhook/admin acabou de
            // marcar como pago entre a consulta e o processamento deste item.
            $fresh = $order->fresh();

            if (! $fresh || ! in_array($fresh->status, [Order::STATUS_AWAITING_PAYMENT, Order::STATUS_PENDING], true)) {
                continue;
            }

            // Auditoria 2026-10-15: o Pix só era confirmado enquanto a tela do
            // cliente consultava o status (não há webhook do tópico "payment"
            // ligado). Quem pagava e fechava a aba tinha o pedido cancelado
            // aqui com o dinheiro já recebido. Antes de cancelar, pergunta ao
            // Mercado Pago; na dúvida (API fora), não cancela nesta rodada.
            $situacao = $this->conferirNoMercadoPago($fresh, $finalizer, $mercadoPago);

            if ($situacao !== self::LIVRE_PARA_CANCELAR) {
                continue;
            }

            $fresh->refresh();

            if (! in_array($fresh->status, [Order::STATUS_AWAITING_PAYMENT, Order::STATUS_PENDING], true)) {
                continue;
            }

            foreach ($finalizer->refundOrder($fresh) as $error) {
                Log::error('orders.expire_abandoned.refund_failed', ['order_id' => $fresh->id, 'message' => $error]);
            }

            $fresh->update(['status' => Order::STATUS_CANCELLED]);
            $finalizer->restoreStockIfNeeded($fresh, 'Pedido abandonado (pagamento não concluído em '.self::ABANDONED_AFTER_MINUTES.' min)');

            $this->info("Pedido #{$fresh->id} cancelado e estoque restaurado.");
        }

        return self::SUCCESS;
    }

    /**
     * Atualiza os pagamentos do Mercado Pago ainda "aguardando confirmação"
     * pelo status real lá (mesmo vocabulário de CheckoutController::status()).
     * Pix ainda pendente é cancelado no Mercado Pago antes de o pedido ser
     * cancelado aqui, para ninguém pagar um pedido que já não existe.
     */
    private function conferirNoMercadoPago(Order $order, OrderPaymentFinalizer $finalizer, MercadoPagoPaymentService $mercadoPago): string
    {
        $pendentes = $order->payments->filter(fn (Payment $payment) => $payment->provider === Payment::PROVIDER_MERCADOPAGO
            && $payment->status === Payment::STATUS_REQUIRES_CONFIRMATION);

        if ($pendentes->isEmpty()) {
            return self::LIVRE_PARA_CANCELAR;
        }

        try {
            foreach ($pendentes as $payment) {
                $mpOrder = $payment->mercadopago_order_id ? $mercadoPago->retrieveOrder($payment->mercadopago_order_id) : null;
                $novoStatus = $mpOrder !== null
                    ? $this->statusDoCartao($mpOrder)
                    : $this->statusDoPix($payment, $mercadoPago);

                if ($novoStatus === Payment::STATUS_CANCELED && $mpOrder !== null) {
                    // Cartão recusado: o motivo fica na tela do pedido.
                    $payment->recusar($mpOrder['transactions']['payments'][0] ?? []);
                } elseif ($novoStatus !== null) {
                    $payment->update(['status' => $novoStatus]);
                }
            }
        } catch (Throwable $exception) {
            Log::warning('orders.expire_abandoned.mercadopago_indisponivel', [
                'order_id' => $order->id,
                'message' => $exception->getMessage(),
            ]);

            return self::NAO_CONFERIDO;
        }

        if ($finalizer->finalize($order->fresh(['payments']))) {
            Log::info('orders.expire_abandoned.pago_no_mercadopago', ['order_id' => $order->id]);
            $this->info("Pedido #{$order->id} estava pago no Mercado Pago: confirmado em vez de cancelado.");

            return self::PAGO;
        }

        return self::LIVRE_PARA_CANCELAR;
    }

    /** Cartão — API de Orders. */
    private function statusDoCartao(array $mpOrder): ?string
    {
        $mpPayment = $mpOrder['transactions']['payments'][0] ?? [];

        return match (true) {
            ($mpPayment['status'] ?? null) === 'processed' => Payment::STATUS_CAPTURED,
            ($mpPayment['status'] ?? null) === 'action_required' && ($mpPayment['status_detail'] ?? null) === 'waiting_capture' => Payment::STATUS_AUTHORIZED,
            in_array($mpPayment['status'] ?? null, ['failed', 'rejected', 'cancelled'], true) => Payment::STATUS_CANCELED,
            default => null,
        };
    }

    /** Pix — API de Payments clássica. Pendente é cancelado lá antes de cancelar o pedido. */
    private function statusDoPix(Payment $payment, MercadoPagoPaymentService $mercadoPago): ?string
    {
        if (! $payment->mercadopago_payment_id) {
            return null;
        }

        $status = $mercadoPago->retrievePayment($payment->mercadopago_payment_id)['status'] ?? null;

        if (in_array($status, ['pending', 'in_process', 'authorized'], true)) {
            // Se o cliente pagou no último segundo, o cancelamento falha ou
            // volta "approved": a resposta decide.
            $status = $mercadoPago->cancelPayment($payment->mercadopago_payment_id)['status'] ?? null;
        }

        return match (true) {
            $status === 'approved' => Payment::STATUS_CAPTURED,
            in_array($status, ['cancelled', 'rejected'], true) => Payment::STATUS_CANCELED,
            default => null,
        };
    }
}
