<?php

namespace App\Modules\Checkout\Jobs;

use App\Modules\Checkout\Models\Order;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use App\Modules\WhatsApp\Services\WhatsAppCloudApiClient;
use App\Modules\WhatsApp\Support\WhatsAppPhone;
use App\Modules\WhatsApp\Support\WhatsAppSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * WhatsApp de pedido aprovado (pedido 2026-10-10): boas-vindas + pós-venda
 * numa mensagem curta, com o botão "Acessar rastreio". Só pedidos da loja.
 * Mensagem iniciada pela loja exige template aprovado na Meta — sem o nome
 * do template no .env (WHATSAPP_TEMPLATE_PEDIDO_APROVADO) não envia nada.
 */
class SendOrderApprovedWhatsAppJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public readonly int $orderId)
    {
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(WhatsAppCloudApiClient $client, WhatsAppSettings $settings): void
    {
        $template = config('services.whatsapp.templates.pedido_aprovado');
        $order = Order::query()->with('user')->find($this->orderId);

        if (! filled($template) || ! $settings->isReadyToSend() || ! $order || $order->origin !== Order::ORIGIN_STORE) {
            return;
        }

        $phone = WhatsAppPhone::normalize($order->shipping_phone ?: $order->user?->phone);
        if ($phone === null) {
            Log::info('whatsapp_pedido_aprovado_sem_telefone', ['order_id' => $order->id]);

            return;
        }

        // Trava: só quem preenche a marca envia (webhook repetido não duplica).
        $claimed = Order::query()->whereKey($order->id)->whereNull('whatsapp_aprovado_enviado_em')
            ->update(['whatsapp_aprovado_enviado_em' => now()]);
        if ($claimed !== 1) {
            return;
        }

        $nome = Str::of($order->shipping_name ?: $order->user?->name ?: 'cliente')->trim()->explode(' ')->first();
        $ref = $order->trackingRef();

        try {
            $response = $client->sendTemplate(
                $phone,
                $template,
                (string) config('services.whatsapp.templates.pedido_aprovado_idioma', 'pt_BR'),
                bodyParams: [$nome, (string) $order->id],
                urlButtonParams: [0 => $ref],
            );
        } catch (Throwable $exception) {
            // Libera a marca pra próxima tentativa da fila.
            Order::query()->whereKey($order->id)->update(['whatsapp_aprovado_enviado_em' => null]);
            Log::warning('whatsapp_pedido_aprovado_falhou', ['order_id' => $order->id, 'error' => $exception->getMessage()]);

            throw $exception;
        }

        // Aparece no histórico da conversa no painel do WhatsApp.
        $conversation = WhatsAppConversation::query()->firstOrCreate(
            ['wa_id' => $phone],
            ['phone' => $phone, 'profile_name' => $order->shipping_name, 'last_message_at' => now(), 'unread_count' => 0],
        );
        $message = WhatsAppMessage::query()->create([
            'conversation_id' => $conversation->id,
            'wa_message_id' => $response['messages'][0]['id'] ?? null,
            'direction' => 'outbound',
            'type' => 'template',
            'body' => "Pedido #{$order->id} aprovado (mensagem automática com o link de rastreio: ".route('rastreio.ver', $ref).')',
            'status' => 'sent',
            'sent_by' => 'system',
            'payload' => ['template' => $template, 'order_id' => $order->id],
            'sent_at' => now(),
        ]);
        $conversation->registerMessage($message);
    }
}
