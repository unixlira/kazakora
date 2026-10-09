<?php

namespace App\Modules\WhatsApp\Jobs;

use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use App\Modules\WhatsApp\Services\GeminiClient;
use App\Modules\WhatsApp\Services\ManuelaAgentClient;
use App\Modules\WhatsApp\Services\ManuelaAutoReplyService;
use App\Modules\WhatsApp\Services\WhatsAppMediaDownloader;
use App\Modules\WhatsApp\Services\WhatsAppOutbox;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Despachado com afterResponse() pelo webhook: a Meta recebe o 200 na hora
 * e a Manuela (Hermes pode levar 10-40s) responde no mesmo processo logo
 * depois. Fila não serve aqui — o worker do homolog só roda ~1x/min.
 */
class ReplyWithManuela
{
    use Dispatchable;

    public function __construct(public int $conversationId, public int $inboundMessageId, public bool $isRetry = false)
    {
    }

    public function handle(ManuelaAutoReplyService $manuela, WhatsAppOutbox $outbox, ManuelaAgentClient $agent, GeminiClient $gemini, WhatsAppMediaDownloader $media): void
    {
        @set_time_limit(150);

        $conversation = WhatsAppConversation::query()->find($this->conversationId);
        $inbound = WhatsAppMessage::query()->find($this->inboundMessageId);

        // "Precisa de humano" é só o alerta na tela: a Manuela segue atendendo
        // até uma pessoa responder (aí a chave desliga). Pedido 2026-10-08:
        // "vamos deixar ela respondendo tudo".
        if (! $conversation || ! $inbound || ! $conversation->ai_enabled) {
            return;
        }

        // Cliente mandou várias mensagens seguidas: só a última dispara
        // resposta (ela já leva o histórico inteiro pra Manuela). E se alguém
        // (Manuela ou pessoa) já respondeu depois dela, não responde de novo.
        // O "só um minutinho" da busca não conta como resposta.
        $alreadyHandled = $conversation->messages()
            ->whereIn('direction', ['inbound', 'outbound'])
            ->where('id', '>', $inbound->id)
            ->get(['id', 'payload'])
            ->contains(fn (WhatsAppMessage $message) => ! ($message->payload['interim'] ?? false));

        if ($alreadyHandled) {
            return;
        }

        if ($inbound->type === 'audio' && $gemini->isConfigured()) {
            $this->transcribe($inbound, $gemini, $media);
        }

        $text = $inbound->type === 'text' ? (string) $inbound->body : (string) ($inbound->payload['transcription'] ?? '');

        // Sem IA, o roteiro fixo não entende foto/áudio: chama uma pessoa.
        if (! $agent->isConfigured() && trim($text) === '') {
            $conversation->update(['needs_human' => true, 'status' => 'needs_human']);

            return;
        }

        // Busca fora do site demora: o cliente recebe o aviso na hora.
        $sendNow = function (string $body) use ($conversation, $outbox) {
            if ($conversation->fresh()->ai_enabled) {
                $outbox->sendText($conversation->fresh(), $body, 'manuela', null, ['interim' => true]);
            }
        };

        $reply = $manuela->buildReply($conversation, $text !== '' ? $text : $inbound->preview(), $sendNow);

        // IA configurada mas o Google não respondeu (sobrecarga): em vez da
        // mensagem robótica do roteiro fixo, tenta de novo em 1 minuto pela
        // fila. Achado real 2026-10-08: áudio sobre a caixa de ferramentas
        // recebeu "vou chamar uma pessoa" porque todos os modelos falharam.
        if (($reply['source'] ?? null) === 'regras' && $agent->isConfigured() && ! $this->isRetry) {
            $conversationId = $this->conversationId;
            $inboundId = $this->inboundMessageId;
            dispatch(static fn () => self::dispatchSync($conversationId, $inboundId, true))->delay(now()->addMinute());

            return;
        }

        // Uma pessoa assumiu enquanto a Manuela pensava: não atropela.
        if (! $conversation->fresh()->ai_enabled) {
            return;
        }

        if ($reply['needs_human'] ?? false) {
            $conversation->update(['needs_human' => true, 'status' => 'needs_human']);
        }

        if (! filled($reply['reply'])) {
            return;
        }

        $outbox->sendText($conversation->fresh(), $reply['reply'], 'manuela', null, ['manuela' => $reply]);
        $conversation->update(['last_auto_reply_at' => now()]);
    }

    /** Áudio do cliente vira texto (Gemini) e fica salvo na mensagem. */
    private function transcribe(WhatsAppMessage $inbound, GeminiClient $gemini, WhatsAppMediaDownloader $media): void
    {
        if (filled($inbound->payload['transcription'] ?? null)) {
            return;
        }

        try {
            $file = $media->download($inbound);

            if (! $file) {
                return;
            }

            $transcription = $gemini->transcribe($file['body'], $file['mime_type']);
            $inbound->update([
                'body' => $transcription,
                'payload' => array_merge($inbound->payload ?? [], ['transcription' => $transcription]),
            ]);
            $lastId = $inbound->conversation?->messages()->whereIn('direction', ['inbound', 'outbound'])->max('id');
            if ((int) $lastId === $inbound->id) {
                $inbound->conversation->update(['last_message_preview' => $inbound->preview()]);
            }
        } catch (Throwable $exception) {
            Log::warning('whatsapp.audio_transcription_failed', ['message_id' => $inbound->id, 'error' => $exception->getMessage()]);
        }
    }
}
