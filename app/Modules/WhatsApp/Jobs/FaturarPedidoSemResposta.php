<?php

namespace App\Modules\WhatsApp\Jobs;

use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Faturamento do pedido do site depois da confirmação pelo WhatsApp
 * (pedido do Lira, 2026-10-17): a NF-e sai assim que o cliente toca em
 * "Tudo Certo" ou, sem resposta, MINUTOS depois da mensagem ("Seu pedido
 * será automaticamente faturado em 30 minutos caso não haja manifestação").
 * "Corrigir Informações" segura a nota até alguém emitir pelo pedido no admin.
 */
class FaturarPedidoSemResposta implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** O texto do template na Meta fala em 30 minutos: mudar os dois juntos. */
    public const MINUTOS = 30;

    public function __construct(public readonly int $orderId)
    {
    }

    public static function agendar(int $orderId): void
    {
        self::dispatch($orderId)->delay(now()->addMinutes(self::MINUTOS));
    }

    /** Dispara a NF-e se o pedido ainda não tem nota nenhuma (toque repetido ou prazo vencido não duplicam). */
    public static function emitir(int $orderId): void
    {
        $order = Order::query()->with('invoice')->find($orderId);
        if (! $order || $order->origin !== Order::ORIGIN_STORE || $order->invoice) {
            return;
        }

        GenerateInvoiceJob::dispatch($order->id);
    }

    public function handle(): void
    {
        $confirmacao = Order::query()->whereKey($this->orderId)->value('whatsapp_confirmacao');

        if ($confirmacao === 'corrigir') {
            Log::info('whatsapp_fatura_segura_correcao', ['order_id' => $this->orderId]);

            return;
        }

        self::emitir($this->orderId);
    }
}
