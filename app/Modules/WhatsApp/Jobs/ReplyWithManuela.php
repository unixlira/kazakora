<?php

namespace App\Modules\WhatsApp\Jobs;

use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use App\Modules\WhatsApp\Services\GeminiClient;
use App\Modules\WhatsApp\Services\ManuelaAgentClient;
use App\Modules\WhatsApp\Services\ManuelaAutoReplyService;
use App\Modules\WhatsApp\Services\ManuelaProductSearch;
use App\Modules\WhatsApp\Services\ManuelaVisao;
use App\Modules\WhatsApp\Services\ManuelaVoz;
use App\Modules\WhatsApp\Support\WhatsAppPhone;
use App\Modules\WhatsApp\Services\WhatsAppMediaDownloader;
use App\Modules\WhatsApp\Services\WhatsAppOutbox;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Despachado com afterResponse() pelo webhook: a Meta recebe o 200 na hora
 * e a Manuela (Hermes pode levar 10-40s) responde no mesmo processo logo
 * depois. Fila não serve aqui — o worker do homolog só roda ~1x/min.
 */
class ReplyWithManuela
{
    use Dispatchable;

    /** Maior arquivo mandado inline ao Gemini (vídeo/PDF), em bytes. */
    private const LIMITE_ARQUIVO = 15 * 1024 * 1024;

    public function __construct(public int $conversationId, public int $inboundMessageId, public bool $isRetry = false)
    {
    }

    public function handle(ManuelaAutoReplyService $manuela, WhatsAppOutbox $outbox, ManuelaAgentClient $agent, GeminiClient $gemini, WhatsAppMediaDownloader $media): void
    {
        @set_time_limit(150);

        // Achado real 2026-10-13: 16 mensagens do Lira em 5s geraram 14
        // respostas, porque cada uma checava "tem mensagem depois?" antes das
        // outras serem gravadas. Espera a rajada acabar e roda UM job por
        // conversa por vez; quem entra depois reconfere já com tudo gravado.
        if (! app()->runningUnitTests()) {
            sleep(max(0, (int) config('services.whatsapp.reply_debounce_seconds', 4)));
        }

        try {
            Cache::lock('manuela:reply:'.$this->conversationId, 150)->block(140, function () use ($manuela, $outbox, $agent, $gemini, $media) {
                $this->processar($manuela, $outbox, $agent, $gemini, $media);
            });
        } catch (LockTimeoutException) {
            Log::info('whatsapp.manuela_conversa_ocupada', ['conversation_id' => $this->conversationId, 'message_id' => $this->inboundMessageId]);
        }
    }

    private function processar(ManuelaAutoReplyService $manuela, WhatsAppOutbox $outbox, ManuelaAgentClient $agent, GeminiClient $gemini, WhatsAppMediaDownloader $media): void
    {

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

        if ($inbound->type === 'image' && $gemini->isConfigured()) {
            $this->enxergar($inbound, $conversation, $media);
        }

        if (in_array($inbound->type, ['video', 'document'], true) && $gemini->isConfigured()) {
            $this->entenderArquivo($inbound, $gemini, $media);
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

        if (! ($inbound->type === 'audio' && $this->audioReplyAllowed($conversation) && $this->sendVoice($conversation, $outbox, $reply))) {
            $outbox->sendText($conversation->fresh(), $reply['reply'], 'manuela', null, ['manuela' => $reply]);
        }

        $conversation->update(['last_auto_reply_at' => now()]);
    }

    /** Resposta falada só para os números liberados (hoje: o do Lira). */
    private function audioReplyAllowed(WhatsAppConversation $conversation): bool
    {
        $numbers = array_filter(array_map([WhatsAppPhone::class, 'normalize'], (array) config('services.whatsapp.audio_reply_numbers', [])));
        $phone = WhatsAppPhone::normalize($conversation->wa_id);

        return $phone !== null && in_array($phone, $numbers, true);
    }

    /** @param  array<string, mixed>  $reply */
    private function sendVoice(WhatsAppConversation $conversation, WhatsAppOutbox $outbox, array $reply): bool
    {
        $path = null;

        try {
            $path = app(ManuelaVoz::class)->gerar((string) $reply['reply']);

            return $outbox->sendVoice($conversation->fresh(), (string) $reply['reply'], $path, 'manuela', ['manuela' => $reply]) !== null;
        } catch (Throwable $exception) {
            Log::warning('whatsapp.resposta_em_audio_falhou', ['conversation_id' => $conversation->id, 'error' => $exception->getMessage()]);

            return false;
        } finally {
            if ($path) {
                @unlink($path);
            }
        }
    }

    /**
     * Foto do cliente (2026-10-11): o Gemini identifica o produto pelo
     * catálogo real. Identificou → esse produto passa a ser o "produto em
     * conversa" (a ficha de onde saem preço e estoque). Não identificou →
     * o produto anterior SAI do contexto, para ela não responder sobre ele.
     */
    private function enxergar(WhatsAppMessage $inbound, WhatsAppConversation $conversation, WhatsAppMediaDownloader $media): void
    {
        if (isset($inbound->payload['visao'])) {
            return;
        }

        try {
            $arquivo = $media->download($inbound);
            $visao = $arquivo ? app(ManuelaVisao::class)->identificar($arquivo['body'], $arquivo['mime_type'], (string) $inbound->body) : null;
        } catch (Throwable $exception) {
            Log::warning('whatsapp.visao_falhou', ['message_id' => $inbound->id, 'error' => $exception->getMessage()]);
            $visao = null;
        }

        $visao ??= ['descricao' => '', 'produto_id' => null, 'nome' => null, 'confianca' => 0.0];
        $inbound->update(['payload' => array_merge($inbound->payload ?? [], ['visao' => $visao])]);

        $metadata = $conversation->metadata ?? [];
        $ficha = $visao['produto_id'] ? app(ManuelaProductSearch::class)->details(ManuelaProductSearch::ORIGIN_STORE, (string) $visao['produto_id']) : null;

        if ($ficha) {
            $metadata[ManuelaAgentClient::PRODUCT_CONTEXT_KEY] = $ficha + ['aberto_em' => now()->toIso8601String()];
        } else {
            unset($metadata[ManuelaAgentClient::PRODUCT_CONTEXT_KEY]);
        }

        $conversation->update(['metadata' => $metadata]);
    }

    /**
     * Vídeo e PDF do cliente (2026-10-13): o Gemini descreve o que aparece e
     * o que é falado; a descrição entra na conversa como o texto da mensagem
     * (junto da legenda, se houver). Arquivo grande ou de outro formato fica
     * só com a legenda.
     */
    private function entenderArquivo(WhatsAppMessage $inbound, GeminiClient $gemini, WhatsAppMediaDownloader $media): void
    {
        if (filled($inbound->payload['transcription'] ?? null)) {
            return;
        }

        try {
            $file = $media->download($inbound);
            $mime = Str::before((string) ($file['mime_type'] ?? ''), ';');
            $aceito = $inbound->type === 'video'
                ? str_starts_with($mime, 'video/')
                : ($mime === 'application/pdf' || str_starts_with($mime, 'text/'));

            if (! $file || ! $aceito || strlen($file['body']) > self::LIMITE_ARQUIVO) {
                return;
            }

            $descricao = $gemini->describe($file['body'], $mime, $inbound->type === 'video' ? 'vídeo' : 'documento');
            $legenda = trim((string) $inbound->body);
            $texto = ($inbound->type === 'video' ? '[Vídeo enviado: ' : '[Documento enviado: ').$descricao.']'.($legenda !== '' ? ' Legenda: '.$legenda : '');

            $inbound->update(['payload' => array_merge($inbound->payload ?? [], ['transcription' => $texto])]);
        } catch (Throwable $exception) {
            Log::warning('whatsapp.arquivo_nao_entendido', ['message_id' => $inbound->id, 'type' => $inbound->type, 'error' => $exception->getMessage()]);
        }
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
