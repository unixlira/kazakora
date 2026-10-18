<?php

namespace App\Modules\Checkout\Jobs;

use App\Modules\Checkout\Models\Order;
use App\Modules\WhatsApp\Jobs\FaturarPedidoSemResposta;
use App\Modules\WhatsApp\Jobs\ResponderConfirmacaoPedido;
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
 * WhatsApp de pedido aprovado: a Manuela confirma o pedido e mostra nome,
 * e-mail e endereço para o cliente conferir, com os botões "Tudo Certo" e
 * "Corrigir Informações" (2026-10-17, modelo Always Fit). Só pedidos da loja.
 * Mensagem iniciada pela loja exige template aprovado na Meta — sem o nome
 * do template no .env (WHATSAPP_TEMPLATE_PEDIDO_APROVADO) não envia nada.
 * A resposta dos botões chega pelo webhook (ResponderConfirmacaoPedido).
 *
 * Com a confirmação ligada, a NF-e do pedido fica com este job: sai no
 * "Tudo Certo" ou 30 min depois da mensagem (FaturarPedidoSemResposta).
 * Se a mensagem não sai (sem telefone, falha esgotada), a nota sai na hora.
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

    /** Pedido do site com a confirmação ligada: a NF-e espera a resposta do cliente. */
    public static function seguraNota(Order $order): bool
    {
        return $order->origin === Order::ORIGIN_STORE
            && filled(config('services.whatsapp.templates.pedido_aprovado'))
            && app(WhatsAppSettings::class)->isReadyToSend();
    }

    public function handle(WhatsAppCloudApiClient $client, WhatsAppSettings $settings): void
    {
        $template = config('services.whatsapp.templates.pedido_aprovado');
        $order = Order::query()->with('user')->find($this->orderId);

        if (! $order || $order->origin !== Order::ORIGIN_STORE) {
            return;
        }

        if (! filled($template) || ! $settings->isReadyToSend()) {
            FaturarPedidoSemResposta::emitir($order->id);

            return;
        }

        $phone = WhatsAppPhone::normalize($order->shipping_whatsapp ?: $order->shipping_phone ?: $order->user?->phone);
        if ($phone === null) {
            Log::info('whatsapp_pedido_aprovado_sem_telefone', ['order_id' => $order->id]);
            FaturarPedidoSemResposta::emitir($order->id);

            return;
        }

        // Trava: só quem preenche a marca envia (webhook repetido não duplica).
        $claimed = Order::query()->whereKey($order->id)->whereNull('whatsapp_aprovado_enviado_em')
            ->update(['whatsapp_aprovado_enviado_em' => now()]);
        if ($claimed !== 1) {
            return;
        }

        $nomeCompleto = trim((string) ($order->shipping_name ?: $order->user?->name)) ?: 'cliente';
        $nome = Str::of($nomeCompleto)->explode(' ')->first();
        $email = $order->shipping_email ?: $order->user?->email ?: 'não informado';
        $imagem = config('services.whatsapp.templates.pedido_aprovado_imagem') ?: asset('images/whatsapp/confirmacao-pedido.jpg');

        try {
            $response = $client->sendTemplate(
                $phone,
                $template,
                (string) config('services.whatsapp.templates.pedido_aprovado_idioma', 'pt_BR'),
                headerMediaType: 'image',
                bodyParams: [$nome, $order->numero, $nomeCompleto, $email, self::endereco($order)],
                quickReplyPayloads: [
                    0 => ResponderConfirmacaoPedido::PAYLOAD_OK.$order->id,
                    1 => ResponderConfirmacaoPedido::PAYLOAD_CORRIGIR.$order->id,
                ],
                headerMediaLink: $imagem,
            );
        } catch (Throwable $exception) {
            // Libera a marca pra próxima tentativa da fila.
            Order::query()->whereKey($order->id)->update(['whatsapp_aprovado_enviado_em' => null]);
            Log::warning('whatsapp_pedido_aprovado_falhou', ['order_id' => $order->id, 'error' => $exception->getMessage()]);

            // Achado real 17/10 (#3210): template ainda em análise na Meta
            // (#132001) falhou igual nas 3 tentativas e segurou a NF-e e a
            // etiqueta por 2,5 min. Erro que não muda tentando de novo
            // desiste na hora e a nota sai (failed()).
            if (self::erroDefinitivo($exception)) {
                $this->fail($exception);

                return;
            }

            throw $exception;
        }

        FaturarPedidoSemResposta::agendar($order->id);

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
            'body' => "Pedido #{$order->numero} confirmado. Conferência de dados enviada: {$nomeCompleto}, {$email}, ".self::endereco($order).' [Tudo Certo] [Corrigir Informações]',
            'status' => 'sent',
            'sent_by' => 'system',
            'payload' => ['template' => $template, 'order_id' => $order->id],
            'sent_at' => now(),
        ]);
        $conversation->registerMessage($message);
    }

    /** Mensagem não saiu depois das tentativas: a nota não fica esperando resposta que não vem. */
    public function failed(?Throwable $exception): void
    {
        FaturarPedidoSemResposta::emitir($this->orderId);
    }

    /**
     * Erros da Meta que não passam com nova tentativa: template inexistente
     * ou não aprovado no idioma (132001), parâmetros que não batem com o
     * template (132000, 132012), template pausado/desativado (132015, 132016)
     * e número que não recebe WhatsApp (131026).
     */
    public static function erroDefinitivo(Throwable $exception): bool
    {
        return (bool) preg_match('/"code":\s*(132000|132001|132012|132015|132016|131026)\b/', $exception->getMessage());
    }

    /** "Rua X, 20 - Apto 3 - Mooca - 03187-040 - São Paulo/SP", sem pedaço vazio. */
    public static function endereco(Order $order): string
    {
        $rua = trim(implode(', ', array_filter([trim((string) $order->shipping_street), trim((string) $order->shipping_number)])));
        $cep = preg_replace('/\D/', '', (string) $order->shipping_zip);
        $cep = strlen($cep) === 8 ? substr($cep, 0, 5).'-'.substr($cep, 5) : $cep;
        $cidade = trim(implode('/', array_filter([trim((string) $order->shipping_city), trim((string) $order->shipping_state)])));

        // Variável de template da Meta não aceita quebra de linha nem tab.
        return (string) preg_replace('/\s+/', ' ', implode(' - ', array_filter([$rua, trim((string) $order->shipping_complement), trim((string) $order->shipping_neighborhood), $cep, $cidade])) ?: 'não informado');
    }
}
