<?php

namespace App\Modules\Checkout\Jobs;

use App\Modules\Checkout\Models\Order;
use App\Modules\WhatsApp\Jobs\ChamarAtendentePedido;
use App\Modules\WhatsApp\Jobs\ResponderNaoRecebi;
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
 * WhatsApp "Seu pedido foi entregue" (2026-10-17, modelo Always Fit): a
 * Manuela avisa quando os Correios registram a entrega, com número do pedido,
 * data e hora, quem recebeu (os Correios não informam hoje) e os botões
 * "Comprovante Entrega" (URL do rastreio), "Não Recebi" e "Falar com
 * Atendente" (respostas rápidas). Só pedidos da loja e só com o template
 * aprovado na Meta no .env (WHATSAPP_TEMPLATE_PEDIDO_ENTREGUE).
 * Disparado por loja:acompanhar-rastreio.
 */
class SendOrderDeliveredWhatsAppJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public readonly int $orderId,
        public readonly ?string $entregueEm = null,
        public readonly ?string $recebedor = null,
    ) {
    }

    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(WhatsAppCloudApiClient $client, WhatsAppSettings $settings): void
    {
        $template = config('services.whatsapp.templates.pedido_entregue');
        $order = Order::query()->with('user')->find($this->orderId);

        if (! filled($template) || ! $settings->isReadyToSend() || ! $order || $order->origin !== Order::ORIGIN_STORE) {
            return;
        }

        $phone = WhatsAppPhone::normalize($order->shipping_whatsapp ?: $order->shipping_phone ?: $order->user?->phone);
        if ($phone === null) {
            Log::info('whatsapp_pedido_entregue_sem_telefone', ['order_id' => $order->id]);

            return;
        }

        // Trava: só quem preenche a marca envia (rotina repetida não duplica).
        $claimed = Order::query()->whereKey($order->id)->whereNull('whatsapp_entregue_em')
            ->update(['whatsapp_entregue_em' => now()]);
        if ($claimed !== 1) {
            return;
        }

        $nome = Str::of(trim((string) ($order->shipping_name ?: $order->user?->name)))->explode(' ')->first() ?: 'cliente';
        $quando = self::quando($this->entregueEm);
        $recebedor = filled($this->recebedor) ? Str::title(trim($this->recebedor)) : 'não informado pelos Correios';
        $imagem = config('services.whatsapp.templates.pedido_entregue_imagem') ?: asset('images/whatsapp/pedido-entregue.jpg');

        try {
            $response = $client->sendTemplate(
                $phone,
                $template,
                (string) config('services.whatsapp.templates.pedido_entregue_idioma', 'pt_BR'),
                headerMediaType: 'image',
                bodyParams: [$nome, $order->numero, $quando, $recebedor],
                // Botão 0: https://kazakora.com/rastreio/{{1}} no template.
                urlButtonParams: [0 => $order->trackingRef()],
                quickReplyPayloads: [
                    1 => ResponderNaoRecebi::PAYLOAD.$order->id,
                    2 => ChamarAtendentePedido::PAYLOAD.$order->id,
                ],
                headerMediaLink: $imagem,
            );
        } catch (Throwable $exception) {
            Order::query()->whereKey($order->id)->update(['whatsapp_entregue_em' => null]);
            Log::warning('whatsapp_pedido_entregue_falhou', ['order_id' => $order->id, 'error' => $exception->getMessage()]);

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
            'body' => "Pedido #{$order->numero} foi entregue em {$quando}. Quem recebeu: {$recebedor}. [Comprovante Entrega] [Não Recebi] [Falar com Atendente]",
            'status' => 'sent',
            'sent_by' => 'system',
            'payload' => ['template' => $template, 'order_id' => $order->id],
            'sent_at' => now(),
        ]);
        $conversation->registerMessage($message);
    }

    /** "09/10 às 12:54" ou, sem data dos Correios, "hoje". */
    public static function quando(?string $data): string
    {
        if (! filled($data)) {
            return 'hoje';
        }

        return Carbon::parse($data)->setTimezone('America/Sao_Paulo')->format('d/m \à\s H:i');
    }
}
