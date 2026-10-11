<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Support\OrderPaymentFinalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

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

    public function handle(OrderPaymentFinalizer $finalizer): int
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

            foreach ($finalizer->refundOrder($fresh) as $error) {
                Log::error('orders.expire_abandoned.refund_failed', ['order_id' => $fresh->id, 'message' => $error]);
            }

            $fresh->update(['status' => Order::STATUS_CANCELLED]);
            $finalizer->restoreStockIfNeeded($fresh, 'Pedido abandonado (pagamento não concluído em '.self::ABANDONED_AFTER_MINUTES.' min)');

            $this->info("Pedido #{$fresh->id} cancelado e estoque restaurado.");
        }

        return self::SUCCESS;
    }
}
