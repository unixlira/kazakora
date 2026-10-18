<?php

namespace App\Modules\Checkout\Jobs;

use App\Modules\Checkout\Models\Order;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Services\WhatsAppOutbox;
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

/**
 * Rastreio do site em texto da Manuela (2026-10-18): uma mensagem por etapa
 * dos Correios (postado, saiu para entrega, aguardando retirada, entregue)
 * enquanto os templates não estão aprovados na Meta. Cada etapa tem a sua
 * marca no pedido, então a rotina de 5 em 5 minutos não repete o aviso.
 * Texto livre só é aceito na janela de 24 h do cliente; fora dela não envia.
 * Com rastreio_texto.redirecionar_para (homolog), vai só para esse número,
 * sem olhar a janela, com o pedido e o cliente no começo.
 * Disparado por loja:acompanhar-rastreio, na hora (dispatchSync).
 */
class SendOrderTrackingTextWhatsAppJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const POSTADO = 'postado';

    public const SAIU_PARA_ENTREGA = 'saiu_para_entrega';

    public const AGUARDANDO_RETIRADA = 'aguardando_retirada';

    public const ENTREGUE = 'entregue';

    /** Coluna do pedido que trava o aviso de cada etapa. */
    public const MARCAS = [
        self::POSTADO => 'whatsapp_enviado_em',
        self::SAIU_PARA_ENTREGA => 'whatsapp_saiu_entrega_em',
        self::AGUARDANDO_RETIRADA => 'whatsapp_retirada_em',
        self::ENTREGUE => 'whatsapp_entregue_em',
    ];

    public int $tries = 1;

    public int $timeout = 30;

    /**
     * @param  array{previsao?: ?string, quando?: ?string, local?: ?string}  $dados
     */
    public function __construct(
        public readonly int $orderId,
        public readonly string $etapa,
        public readonly array $dados = [],
    ) {
    }

    public function handle(WhatsAppOutbox $outbox, WhatsAppSettings $settings): void
    {
        $marca = self::MARCAS[$this->etapa] ?? null;
        $order = Order::query()->with('user')->find($this->orderId);

        if ($marca === null || ! config('services.whatsapp.rastreio_texto.ativo') || ! $settings->isReadyToSend() || ! $order || $order->origin !== Order::ORIGIN_STORE) {
            return;
        }

        $redirecionar = WhatsAppPhone::normalize(config('services.whatsapp.rastreio_texto.redirecionar_para'));
        $phone = $redirecionar ?? WhatsAppPhone::normalize($order->shipping_whatsapp ?: $order->shipping_phone ?: $order->user?->phone);
        if ($phone === null) {
            Log::info('whatsapp_rastreio_texto_sem_telefone', ['order_id' => $order->id, 'etapa' => $this->etapa]);

            return;
        }

        $conversation = WhatsAppConversation::query()->firstOrCreate(
            ['wa_id' => $phone],
            ['phone' => $phone, 'profile_name' => $redirecionar ? null : $order->shipping_name, 'last_message_at' => now(), 'unread_count' => 0],
        );
        if ($redirecionar === null && ! $conversation->insideServiceWindow()) {
            Log::info('whatsapp_rastreio_texto_fora_da_janela', ['order_id' => $order->id, 'etapa' => $this->etapa]);

            return;
        }

        // Trava: só quem preenche a marca envia (rotina repetida não duplica).
        $claimed = Order::query()->whereKey($order->id)->whereNull($marca)->update([$marca => now()]);
        if ($claimed !== 1) {
            return;
        }

        $texto = self::texto($this->etapa, $order, $this->dados);
        if ($redirecionar !== null) {
            $texto = "[Teste do rastreio] Pedido #{$order->numero}, cliente ".trim((string) $order->shipping_name).":\n\n".$texto;
        }

        $message = $outbox->sendText($conversation, $texto, 'manuela', extraPayload: ['order_id' => $order->id, 'rastreio_etapa' => $this->etapa]);
        if ($message->status === 'failed') {
            Order::query()->whereKey($order->id)->update([$marca => null]);
        }
    }

    /** @param  array{previsao?: ?string, quando?: ?string, local?: ?string}  $dados */
    public static function texto(string $etapa, Order $order, array $dados = []): string
    {
        $nome = Str::of(trim((string) ($order->shipping_name ?: $order->user?->name)))->explode(' ')->first() ?: 'cliente';
        $link = route('rastreio.ver', $order->trackingRef());
        $quando = filled($dados['quando'] ?? null)
            ? Carbon::parse($dados['quando'])->setTimezone('America/Sao_Paulo')->format('d/m \à\s H:i')
            : null;

        return match ($etapa) {
            self::POSTADO => "Oi, {$nome}! Aqui é a Manuela, da KazaKora. Seu pedido #{$order->numero} foi postado nos Correios. "
                .'Previsão de entrega: '.SendOrderShippedWhatsAppJob::previsao($dados['previsao'] ?? null).".\n\nAcompanhe aqui: {$link}",
            self::SAIU_PARA_ENTREGA => "Oi, {$nome}! Seu pedido #{$order->numero} saiu para entrega com os Correios"
                .($quando ? " ({$quando})" : '').". O carteiro está a caminho do seu endereço.\n\nAcompanhe aqui: {$link}",
            self::AGUARDANDO_RETIRADA => "Oi, {$nome}! Os Correios não conseguiram entregar o pedido #{$order->numero} e ele está aguardando retirada"
                .(filled($dados['local'] ?? null) ? " em {$dados['local']}" : ' na agência').". Leve um documento com foto.\n\n"
                ."Detalhes aqui: {$link}\n\nPrecisa de ajuda? É só responder esta mensagem.",
            self::ENTREGUE => "Oi, {$nome}! Os Correios registraram a entrega do pedido #{$order->numero}"
                .($quando ? " em {$quando}" : '').". Esperamos que goste!\n\n"
                .'Se não recebeu, responda esta mensagem que a gente resolve.',
        };
    }
}
