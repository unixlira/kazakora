<?php

namespace App\Modules\WhatsApp\Jobs;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Services\WhatsAppOutbox;
use App\Modules\WhatsApp\Support\WhatsAppPhone;
use App\Notifications\NaoRecebiPedidoWhatsAppNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Cliente tocou em "Não Recebi" no aviso de pedido entregue
 * (SendOrderDeliveredWhatsAppJob): grava no pedido, a conversa vai para
 * "precisa de humano", a Manuela responde e os admins recebem aviso no
 * sino para abrir a reclamação com os Correios.
 */
class ResponderNaoRecebi implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const PAYLOAD = 'pedido_entregue:nao_recebi:';

    public function __construct(
        public readonly int $conversationId,
        public readonly string $payload,
    ) {
    }

    /** Id do pedido, se o payload é deste botão. */
    public static function interpretar(?string $payload): ?int
    {
        return preg_match('/^pedido_entregue:nao_recebi:(\d+)$/', (string) $payload, $m) ? (int) $m[1] : null;
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
            Log::warning('whatsapp_nao_recebi_numero_diferente', ['conversation_id' => $conversation->id, 'order_id' => $orderId]);

            return;
        }

        // Toque repetido não responde nem avisa de novo.
        $marcou = Order::query()->whereKey($order->id)->whereNull('whatsapp_nao_recebi_em')
            ->update(['whatsapp_nao_recebi_em' => now()]);
        if ($marcou !== 1) {
            return;
        }

        $nome = Str::of((string) ($order->shipping_name ?: $order->user?->name))->trim()->explode(' ')->first() ?: '';
        $saudacao = $nome !== '' ? ", {$nome}" : '';

        $conversation->update(['needs_human' => true, 'status' => 'needs_human']);
        $outbox->sendText($conversation, "Poxa{$saudacao}, sinto muito! Já avisei o nosso time e vamos verificar com os Correios a entrega do pedido #{$order->numero}. Se puder, me conta aqui se alguém da portaria ou da família pode ter recebido. Logo alguém fala com você.", 'manuela', null, ['pedido_entregue' => 'nao_recebi', 'order_id' => $order->id]);

        $admins = User::query()->where('role', User::ROLE_ADMIN)->get();
        if ($admins->isNotEmpty()) {
            Notification::send($admins, new NaoRecebiPedidoWhatsAppNotification($order));
        }
    }
}
