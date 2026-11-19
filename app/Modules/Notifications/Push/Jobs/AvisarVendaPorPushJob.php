<?php

namespace App\Modules\Notifications\Push\Jobs;

use App\Modules\Checkout\Models\Order;
use App\Modules\Notifications\Push\AppsDeVendas;
use App\Modules\Notifications\Push\AvisosPush;
use App\Modules\Notifications\Push\WebPush;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Aviso de venda no app KazaKora ou FerraKora quando o pagamento do pedido
 * do site é confirmado (OrderPaymentFinalizer, 2026-11-19).
 *
 * Um aviso por pedido: quem grava a linha em push_vendas_avisadas manda.
 * Webhook repetido, finalize chamado duas vezes ou job na fila em dobro
 * caem no insertOrIgnore e não avisam de novo. Uma tentativa só: um aviso
 * de venda atrasado meia hora mais confunde do que ajuda.
 */
class AvisarVendaPorPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly int $orderId)
    {
    }

    public function handle(AvisosPush $avisos): void
    {
        try {
            $order = Order::query()->find($this->orderId);
            if (! $order || ! in_array($order->status, [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED], true)) {
                return;
            }

            $app = AppsDeVendas::doPedido($order);
            if ($app === null || ! WebPush::daConfig()->configurado()) {
                return;
            }

            $travou = DB::table('push_vendas_avisadas')->insertOrIgnore([
                'order_id' => $order->id,
                'app' => $app,
                'created_at' => now(),
            ]);
            if ($travou !== 1) {
                return;
            }

            $resultado = $avisos->enviar($app, $avisos->mensagemDaVenda($order, $app));

            DB::table('push_vendas_avisadas')->where('order_id', $order->id)->update(['enviados' => $resultado['enviados']]);
            Log::info('push.venda', ['order_id' => $order->id, 'app' => $app, ...$resultado]);
        } catch (Throwable $e) {
            // Aviso nunca derruba nada nem volta para a fila.
            Log::warning('push.venda_falhou', ['order_id' => $this->orderId, 'erro' => $e->getMessage()]);
        }
    }
}
