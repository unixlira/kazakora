<?php

namespace App\Modules\WhatsApp\Jobs;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Services\WhatsAppOutbox;
use App\Modules\WhatsApp\Support\WhatsAppPhone;
use App\Notifications\CorrecaoPedidoWhatsAppNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Cliente tocou em "Tudo Certo" ou "Corrigir Informações" na confirmação do
 * pedido (SendOrderApprovedWhatsAppJob). Grava a resposta no pedido e a
 * Manuela responde. Correção: conversa vai para "precisa de humano" e os
 * admins recebem aviso no sino, para ajustar antes da etiqueta.
 * "Tudo Certo" libera a NF-e na hora; "Corrigir" segura a nota até alguém
 * emitir pelo pedido (FaturarPedidoSemResposta).
 */
class ResponderConfirmacaoPedido implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const PAYLOAD_OK = 'confirmacao_pedido:ok:';

    public const PAYLOAD_CORRIGIR = 'confirmacao_pedido:corrigir:';

    public function __construct(
        public readonly int $conversationId,
        public readonly string $payload,
    ) {
    }

    /** Payload de botão desta confirmação? Devolve [resposta, id do pedido]. */
    public static function interpretar(?string $payload): ?array
    {
        if (! preg_match('/^confirmacao_pedido:(ok|corrigir):(\d+)$/', (string) $payload, $m)) {
            return null;
        }

        return [$m[1], (int) $m[2]];
    }

    public function handle(WhatsAppOutbox $outbox): void
    {
        $conversation = WhatsAppConversation::query()->find($this->conversationId);
        $botao = self::interpretar($this->payload);
        if (! $conversation || ! $botao) {
            return;
        }

        [$resposta, $orderId] = $botao;
        $order = Order::query()->with('user')->find($orderId);

        // O botão só vale para o número que recebeu a mensagem do pedido.
        $telefones = array_filter(array_map([WhatsAppPhone::class, 'normalize'], [$order?->shipping_whatsapp, $order?->shipping_phone, $order?->user?->phone]));
        if (! $order || ! in_array(WhatsAppPhone::normalize($conversation->wa_id), $telefones, true)) {
            Log::warning('whatsapp_confirmacao_pedido_numero_diferente', ['conversation_id' => $conversation->id, 'order_id' => $orderId]);

            return;
        }

        // Toque repetido no mesmo botão não responde de novo; trocar de ideia vale.
        $mudou = Order::query()->whereKey($order->id)
            ->where(fn ($q) => $q->whereNull('whatsapp_confirmacao')->orWhere('whatsapp_confirmacao', '!=', $resposta))
            ->update(['whatsapp_confirmacao' => $resposta, 'whatsapp_confirmacao_em' => now()]);
        if ($mudou !== 1) {
            return;
        }

        $nome = Str::of((string) ($order->shipping_name ?: $order->user?->name))->trim()->explode(' ')->first() ?: '';
        $saudacao = $nome !== '' ? ", {$nome}" : '';

        if ($resposta === 'ok') {
            FaturarPedidoSemResposta::emitir($order->id);
            $outbox->sendText($conversation, "Perfeito{$saudacao}! Seu pedido #{$order->numero} já segue para a separação. Qualquer dúvida, é só me chamar por aqui.", 'manuela', null, ['confirmacao_pedido' => 'ok', 'order_id' => $order->id]);

            return;
        }

        $outbox->sendText($conversation, "Claro{$saudacao}! Me conta aqui o que precisa corrigir no pedido #{$order->numero} (nome, e-mail ou endereço) que o nosso time ajusta antes do faturamento e do envio.", 'manuela', null, ['confirmacao_pedido' => 'corrigir', 'order_id' => $order->id]);
        $conversation->update(['needs_human' => true, 'status' => 'needs_human']);

        $admins = User::query()->where('role', User::ROLE_ADMIN)->get();
        if ($admins->isNotEmpty()) {
            Notification::send($admins, new CorrecaoPedidoWhatsAppNotification($order));
        }
    }
}
