<?php

namespace App\Modules\Checkout\Jobs;

use App\Modules\Checkout\Models\Order;
use App\Modules\WhatsApp\Jobs\ChamarAtendentePedido;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use App\Modules\WhatsApp\Services\WhatsAppCloudApiClient;
use App\Modules\WhatsApp\Services\WhatsAppModelos;
use App\Modules\WhatsApp\Support\WhatsAppNumeros;
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
 * WhatsApp "Seu pedido está aguardando retirada na agência" (2026-11-20,
 * pedido #4172): os Correios deixaram o pacote na agência e o cliente não
 * tinha como saber onde. Vai com o endereço da agência, o código de
 * rastreio, a data-limite e os botões "Ir para o rastreio" e "Falar com
 * Atendente". Template pedido_aguardando_retirada_<loja>. Disparado por
 * loja:avisar-retirada; trava em orders.whatsapp_retirada_em.
 */
class AvisarRetiradaNaAgenciaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public readonly int $orderId, public readonly string $endereco, public readonly ?string $ate = null) {}

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(WhatsAppCloudApiClient $client, WhatsAppSettings $settings): void
    {
        $order = Order::query()->with('user')->find($this->orderId);
        if (! $order || $order->origin !== Order::ORIGIN_STORE || $order->status !== Order::STATUS_SHIPPED) {
            return;
        }

        $loja = WhatsAppNumeros::lojaDoPedido($order);
        $template = WhatsAppNumeros::template($loja, 'pedido_retirada');
        if (! filled($template) || ! $settings->isReadyToSend($loja)) {
            return;
        }

        $phone = WhatsAppPhone::normalize($order->shipping_whatsapp ?: $order->shipping_phone ?: $order->user?->phone);
        if ($phone === null) {
            Log::info('whatsapp_retirada_sem_telefone', ['order_id' => $order->id]);

            return;
        }

        $claimed = Order::query()->whereKey($order->id)->whereNull('whatsapp_retirada_em')->update(['whatsapp_retirada_em' => now()]);
        if ($claimed !== 1) {
            return;
        }

        $nome = Str::of(trim((string) ($order->shipping_name ?: $order->user?->name)))->explode(' ')->first() ?: 'cliente';
        $codigo = (string) $order->trackingCode();
        $ate = $this->ate ? Carbon::parse($this->ate)->format('d/m/Y') : 'os próximos dias';
        $imagem = asset($loja === WhatsAppNumeros::FERRAKORA
            ? 'images/whatsapp/pedido-aguardando-retirada-ferrakora.jpg'
            : 'images/whatsapp/pedido-aguardando-retirada.jpg');

        try {
            $response = $client->paraLoja($loja)->sendTemplate(
                $phone,
                $template,
                (string) WhatsAppNumeros::template($loja, 'pedido_retirada_idioma', 'pt_BR'),
                headerMediaType: 'image',
                bodyParams: [$nome, $order->numero, $this->endereco, $codigo, $ate],
                urlButtonParams: [0 => $order->trackingRef()],
                quickReplyPayloads: [1 => ChamarAtendentePedido::PAYLOAD.$order->id],
                headerMediaLink: $imagem,
            );
        } catch (Throwable $exception) {
            Order::query()->whereKey($order->id)->update(['whatsapp_retirada_em' => null]);
            Log::warning('whatsapp_retirada_falhou', ['order_id' => $order->id, 'error' => $exception->getMessage()]);

            throw $exception;
        }

        $conversation = WhatsAppConversation::paraNumero($phone, $loja, [
            'phone' => $phone,
            'profile_name' => $order->shipping_name,
            'last_message_at' => now(),
            'unread_count' => 0,
        ]);
        $message = WhatsAppMessage::query()->create([
            'conversation_id' => $conversation->id,
            'wa_message_id' => $response['messages'][0]['id'] ?? null,
            'direction' => 'outbound',
            'type' => 'template',
            'body' => "Pedido #{$order->numero} aguardando retirada na agência: {$this->endereco}. Código {$codigo}. Retirar até {$ate}. [Ir para o rastreio] [Falar com Atendente]",
            'status' => 'sent',
            'sent_by' => 'system',
            // Dados do envio: a tela de Conversas redesenha o balão igual ao WhatsApp.
            'payload' => WhatsAppModelos::payloadDoEnvio(
                $template,
                (string) WhatsAppNumeros::template($loja, 'pedido_retirada_idioma', 'pt_BR'),
                [$nome, $order->numero, $this->endereco, $codigo, $ate],
                $imagem,
                [0 => $order->trackingRef()],
            ) + ['order_id' => $order->id],
            'sent_at' => now(),
        ]);
        $conversation->registerMessage($message);
    }
}
