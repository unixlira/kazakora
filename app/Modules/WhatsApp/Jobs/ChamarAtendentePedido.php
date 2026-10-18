<?php

namespace App\Modules\WhatsApp\Jobs;

use App\Modules\Checkout\Models\Order;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Services\WhatsAppOutbox;
use App\Modules\WhatsApp\Support\WhatsAppPhone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Cliente tocou em "Falar com Atendente" no aviso de pedido enviado ou
 * entregue (SendOrderShippedWhatsAppJob, SendOrderDeliveredWhatsAppJob): a conversa vai para "precisa de humano"
 * no painel do WhatsApp e a Manuela avisa que o time já vem.
 */
class ChamarAtendentePedido implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const PAYLOAD = 'pedido_enviado:atendente:';

    public function __construct(
        public readonly int $conversationId,
        public readonly string $payload,
    ) {
    }

    /** Id do pedido, se o payload é deste botão. */
    public static function interpretar(?string $payload): ?int
    {
        return preg_match('/^pedido_enviado:atendente:(\d+)$/', (string) $payload, $m) ? (int) $m[1] : null;
    }

    public function handle(WhatsAppOutbox $outbox): void
    {
        $conversation = WhatsAppConversation::query()->find($this->conversationId);
        $orderId = self::interpretar($this->payload);
        if (! $conversation || ! $orderId) {
            return;
        }

        $order = Order::query()->with('user')->find($orderId);
        $telefones = array_filter(array_map([WhatsAppPhone::class, 'normalize'], [$order?->shipping_whatsapp, $order?->shipping_phone, $order?->user?->phone]));
        if (! $order || ! in_array(WhatsAppPhone::normalize($conversation->wa_id), $telefones, true)) {
            Log::warning('whatsapp_atendente_pedido_numero_diferente', ['conversation_id' => $conversation->id, 'order_id' => $orderId]);

            return;
        }

        // Toque repetido enquanto a conversa já espera humano não repete a resposta.
        if ($conversation->needs_human) {
            return;
        }

        $conversation->update(['needs_human' => true, 'status' => 'needs_human']);
        $outbox->sendText($conversation, "Certo! Já chamei o nosso time para falar com você sobre o pedido #{$order->numero}. Pode ir escrevendo aqui a sua dúvida que logo alguém responde.", 'manuela', null, ['pedido_enviado' => 'atendente', 'order_id' => $order->id]);
    }
}
