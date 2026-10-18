<?php

namespace App\Modules\Checkout\Jobs;

use App\Modules\Checkout\Models\Order;
use App\Modules\WhatsApp\Jobs\ChamarAtendentePedido;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * WhatsApp "Seu pedido saiu para entrega" (2026-10-17, modelo Always Fit):
 * a Manuela avisa quando os Correios registram a postagem, com número do
 * pedido, previsão de entrega e os botões "Ir para o rastreio" (URL) e
 * "Falar com Atendente" (resposta rápida). Só pedidos da loja e só com o
 * template aprovado na Meta no .env (WHATSAPP_TEMPLATE_PEDIDO_ENVIADO).
 * Disparado por loja:acompanhar-rastreio.
 */
class SendOrderShippedWhatsAppJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public readonly int $orderId, public readonly ?string $previsao = null)
    {
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(WhatsAppCloudApiClient $client, WhatsAppSettings $settings): void
    {
        $template = config('services.whatsapp.templates.pedido_enviado');
        $order = Order::query()->with('user')->find($this->orderId);

        if (! filled($template) || ! $settings->isReadyToSend() || ! $order || $order->origin !== Order::ORIGIN_STORE) {
            return;
        }

        $phone = WhatsAppPhone::normalize($order->shipping_whatsapp ?: $order->shipping_phone ?: $order->user?->phone);
        if ($phone === null) {
            Log::info('whatsapp_pedido_enviado_sem_telefone', ['order_id' => $order->id]);

            return;
        }

        // Trava: só quem preenche a marca envia (rotina repetida não duplica).
        $claimed = Order::query()->whereKey($order->id)->whereNull('whatsapp_enviado_em')
            ->update(['whatsapp_enviado_em' => now()]);
        if ($claimed !== 1) {
            return;
        }

        $nome = Str::of(trim((string) ($order->shipping_name ?: $order->user?->name)))->explode(' ')->first() ?: 'cliente';
        $previsao = self::previsao($this->previsao);
        $imagem = config('services.whatsapp.templates.pedido_enviado_imagem') ?: asset('images/whatsapp/pedido-enviado.jpg');

        try {
            $response = $client->sendTemplate(
                $phone,
                $template,
                (string) config('services.whatsapp.templates.pedido_enviado_idioma', 'pt_BR'),
                headerMediaType: 'image',
                bodyParams: [$nome, $order->numero, $previsao],
                // Botão 0: https://kazakora.com/rastreio/{{1}} no template.
                urlButtonParams: [0 => $order->trackingRef()],
                quickReplyPayloads: [1 => ChamarAtendentePedido::PAYLOAD.$order->id],
                headerMediaLink: $imagem,
            );
        } catch (Throwable $exception) {
            Order::query()->whereKey($order->id)->update(['whatsapp_enviado_em' => null]);
            Log::warning('whatsapp_pedido_enviado_falhou', ['order_id' => $order->id, 'error' => $exception->getMessage()]);

            throw $exception;
        }

        $conversation = WhatsAppConversation::query()->firstOrCreate(
            ['wa_id' => $phone],
            ['phone' => $phone, 'profile_name' => $order->shipping_name, 'last_message_at' => now(), 'unread_count' => 0],
        );
        $message = WhatsAppMessage::query()->create([
            'conversation_id' => $conversation->id,
            'wa_message_id' => $response['messages'][0]['id'] ?? null,
            'direction' => 'outbound',
            'type' => 'template',
            'body' => "Pedido #{$order->numero} saiu para entrega. Previsão: {$previsao}. [Ir para o rastreio] [Falar com Atendente]",
            'status' => 'sent',
            'sent_by' => 'system',
            'payload' => ['template' => $template, 'order_id' => $order->id],
            'sent_at' => now(),
        ]);
        $conversation->registerMessage($message);
    }

    /** "até 22/10 (quinta-feira)" ou, sem data dos Correios, "nos próximos dias úteis". */
    public static function previsao(?string $data): string
    {
        if (! filled($data)) {
            return 'nos próximos dias úteis';
        }

        $dia = Carbon::parse($data, 'America/Sao_Paulo')->locale('pt_BR');

        return 'até '.$dia->format('d/m').' ('.$dia->dayName.')';
    }
}
