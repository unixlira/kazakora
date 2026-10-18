<?php

namespace App\Modules\Checkout\Jobs;

use App\Modules\Checkout\Models\Order;
use App\Services\Meta\ConversoesMeta;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Purchase do servidor quando o pagamento do pedido do site aprova (Pix,
 * boleto e cartão passam pelo OrderPaymentFinalizer). Pedido 2026-10-18.
 *
 * - Só kazakora.com (origin loja, sem site_origem): nunca marketplace,
 *   FerraKora ou Beauty Kora.
 * - Só com o OK de cookies dado no checkout (orders.meta_rastreio).
 * - Uma vez só por pedido: quem vira orders.meta_purchase_enviado_em de
 *   nulo para preenchido manda; se a Meta falhar, a marca volta a nulo e a
 *   fila tenta de novo.
 * - event_id "purchase-{id}", o mesmo do Pixel do front.
 */
class EnviarPurchaseMetaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public readonly int $orderId)
    {
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(ConversoesMeta $conversoes): void
    {
        $order = Order::query()->with(['items', 'user'])->find($this->orderId);

        if (! $order || ! ConversoesMeta::pedidoDaLoja($order) || $order->status !== Order::STATUS_PAID) {
            return;
        }

        if (empty($order->meta_rastreio)) {
            Log::info('meta_capi.pulado', ['motivo' => 'sem_consentimento', 'event_name' => 'Purchase', 'order_id' => $order->id]);

            return;
        }

        if (! $conversoes->ativo()) {
            // Sem marcar: ligando a API depois, pedido novo sai normalmente.
            $conversoes->enviar(['event_name' => 'Purchase', 'event_id' => ConversoesMeta::eventIdDoPurchase($order)]);

            return;
        }

        $travou = Order::query()->whereKey($order->id)->whereNull('meta_purchase_enviado_em')
            ->update(['meta_purchase_enviado_em' => now()]);

        if ($travou !== 1) {
            return;
        }

        try {
            $conversoes->enviar($conversoes->eventoPurchase($order));
        } catch (Throwable $e) {
            Order::query()->whereKey($order->id)->update(['meta_purchase_enviado_em' => null]);

            throw $e;
        }

        // Saiu: o rastreio (IP, navegador, _fbp/_fbc) não serve mais para nada.
        Order::query()->whereKey($order->id)->update(['meta_rastreio' => null]);
    }
}
