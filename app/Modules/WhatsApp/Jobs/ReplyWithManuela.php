<?php

namespace App\Modules\WhatsApp\Jobs;

use App\Models\User;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use App\Modules\WhatsApp\Services\GeminiClient;
use App\Modules\WhatsApp\Services\ManuelaAgentClient;
use App\Modules\WhatsApp\Services\ManuelaAutoReplyService;
use App\Modules\WhatsApp\Services\ManuelaProductSearch;
use App\Modules\WhatsApp\Services\ManuelaVisao;
use App\Modules\WhatsApp\Services\WhatsAppMediaDownloader;
use App\Modules\WhatsApp\Services\WhatsAppOutbox;
use App\Modules\WhatsApp\Support\ContextoDoCliente;
use App\Modules\WhatsApp\Support\ManuelaEncerramento;
use App\Notifications\ManuelaAlertaAtendimentoNotification;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
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

    /** @var array<int, true> Conversas cuja trava este processo já segura. */
    private static array $emAndamento = [];

    public function __construct(public int $conversationId, public int $inboundMessageId, public bool $isRetry = false)
    {
    }

    public function handle(ManuelaAutoReplyService $manuela, WhatsAppOutbox $outbox, ManuelaAgentClient $agent, GeminiClient $gemini, WhatsAppMediaDownloader $media): void
    {
        if (! app()->runningUnitTests()) {
            @set_time_limit(150);
        }

        // Achado real 2026-10-13: 16 mensagens do Lira em 5s geraram 14
        // respostas, porque cada uma checava "tem mensagem depois?" antes das
        // outras serem gravadas. Espera a rajada acabar e roda UM job por
        // conversa por vez; quem entra depois reconfere já com tudo gravado.
        // Nova tentativa que roda dentro do próprio job (fila síncrona) já
        // está sob a trava da conversa: não pode esperar por si mesma.
        if (isset(self::$emAndamento[$this->conversationId])) {
            $this->processar($manuela, $outbox, $agent, $gemini, $media);

            return;
        }

        if (! app()->runningUnitTests()) {
            sleep(max(0, (int) config('services.whatsapp.reply_debounce_seconds', 4)));
        }

        try {
            Cache::lock('manuela:reply:'.$this->conversationId, 150)->block(140, function () use ($manuela, $outbox, $agent, $gemini, $media) {
                self::$emAndamento[$this->conversationId] = true;

                try {
                    $this->processar($manuela, $outbox, $agent, $gemini, $media);
                } finally {
                    unset(self::$emAndamento[$this->conversationId]);
                }
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

        // Reação (👍 🙏) e figurinha não são mensagem: a Manuela não responde
        // (pedido do Lira 2026-11-18). O webhook já pula reação; esta trava
        // cobre o caminho da chave ligada na tela, que responde a última.
        if (in_array($inbound->type, ['reaction', 'sticker'], true)) {
            return;
        }

        // Resposta automática do outro lado (WhatsApp Business do cliente com
        // "seja bem-vindo, deixe seu recado"): a Manuela não responde, senão vira
        // robô conversando com robô (achado do Lira 2026-11-23). Sinais: texto de
        // mensagem de ausência/saudação automática, ou o mesmo texto que o cliente
        // já mandou antes nesta conversa.
        if ($inbound->type === 'text' && self::pareceRespostaAutomatica((string) $inbound->body, $conversation, $inbound)) {
            Log::info('whatsapp.manuela_ignorou_resposta_automatica', ['conversation_id' => $conversation->id, 'message_id' => $inbound->id]);

            return;
        }

        // Cliente mandou várias mensagens seguidas: só a última dispara
        // resposta (ela já leva o histórico inteiro pra Manuela). E se alguém
        // (Manuela ou pessoa) já respondeu depois dela, não responde de novo.
        // O "só um minutinho" da busca não conta como resposta.
        $alreadyHandled = $conversation->messages()
            ->whereIn('direction', ['inbound', 'outbound'])
            ->where('id', '>', $inbound->id)
            ->get(['id', 'type', 'payload'])
            // Reação do cliente depois da pergunta dele não é resposta nossa.
            ->contains(fn (WhatsAppMessage $message) => $message->type !== 'reaction' && ! ($message->payload['interim'] ?? false));

        if ($alreadyHandled) {
            return;
        }

        // Comprovante do Pix (foto) ou "Feito"/"paguei" logo depois de um pedido:
        // agradece e confirma o pedido, sem pergunta (caso real 26/11/2026, conversa 70).
        if ($this->confirmouPagamento($conversation, $inbound, $outbox)) {
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

        // "Obrigada", "ok, valeu", "tá bom, aguardo": fim de conversa. A Manuela
        // não responde e o atendimento é encerrado (pedido do Lira 2026-11-18).
        if (in_array($inbound->type, ['text', 'audio'], true) && ManuelaEncerramento::encerra($text, $this->deixamosPergunta($conversation, $inbound))) {
            $this->encerrar($conversation, $inbound);

            return;
        }

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

        // FerraKora: Procon, Reclame Aqui, golpe, advogado... a Manuela para
        // (chave desliga) e fica só a pessoa do time.
        if ($reply['stop_bot'] ?? false) {
            $conversation->update(['ai_enabled' => false]);
        }

        // Preço (pedido do Lira 2026-10-31): cliente recebe o aviso da gerência,
        // a chave desliga e o time é alertado para assumir.
        if ($reply['gerencia'] ?? false) {
            $this->alertarAtendimento($conversation, $text !== '' ? $text : $inbound->preview(), (string) ($reply['rascunho'] ?? ''));
        }

        // Resposta negativa ("não sei", "não tem"): nada vai ao cliente; o
        // time de Atendimento é alertado para assumir (pedido do Lira 2026-10-25).
        if ($reply['alerta_atendimento'] ?? false) {
            $this->alertarAtendimento($conversation, $text !== '' ? $text : $inbound->preview(), (string) ($reply['rascunho'] ?? ''));

            return;
        }

        if (! filled($reply['reply'])) {
            return;
        }

        // Manuela entende áudio (transcrição), mas responde SEMPRE por texto.
        $outbox->sendText($conversation->fresh(), $reply['reply'], 'manuela', null, ['manuela' => $reply]);

        $conversation->update(['last_auto_reply_at' => now()]);
    }

    /**
     * Foto (comprovante) ou "feito"/"paguei" com pedido do cliente feito há
     * poucas horas: resposta pronta confirmando o pedido. Uma vez por pedido
     * (a segunda foto já vai para a Manuela, com o pedido no contexto), para
     * não virar loop com resposta automática.
     */
    private function confirmouPagamento(WhatsAppConversation $conversation, WhatsAppMessage $inbound, WhatsAppOutbox $outbox): bool
    {
        $foto = $inbound->type === 'image';
        if (! $foto && ! ($inbound->type === 'text' && ContextoDoCliente::avisoDePagamento((string) $inbound->body))) {
            return false;
        }

        try {
            $contexto = app(ContextoDoCliente::class)->montar($conversation);
        } catch (Throwable $e) {
            Log::warning('whatsapp.manuela_contexto_falhou', ['conversation_id' => $conversation->id, 'erro' => $e->getMessage()]);

            return false;
        }

        $pedido = ContextoDoCliente::pedidoRecente($contexto);
        if (! $pedido) {
            return false;
        }

        $jaConfirmou = $conversation->messages()
            ->where('direction', 'outbound')
            ->where('created_at', '>=', now()->subDays(2))
            ->get(['id', 'payload'])
            ->contains(fn (WhatsAppMessage $m) => (int) ($m->payload['manuela_pedido_confirmado'] ?? 0) === (int) $pedido['id']);
        if ($jaConfirmou) {
            return false;
        }

        if (! $conversation->fresh()->ai_enabled) {
            return true;
        }

        $nome = $pedido['nome'] ?? null;
        $outbox->sendText($conversation->fresh(), ContextoDoCliente::respostaComprovante($pedido, $nome), 'manuela', null, [
            'manuela' => ['intent' => 'comprovante_pedido', 'source' => 'contexto'],
            'manuela_pedido_confirmado' => $pedido['id'],
            'order_id' => $pedido['id'],
        ]);
        $conversation->update(['last_auto_reply_at' => now()]);

        Log::info('whatsapp.manuela_confirmou_pagamento', ['conversation_id' => $conversation->id, 'order_id' => $pedido['id'], 'message_id' => $inbound->id]);

        return true;
    }

    /** A nossa última mensagem antes da do cliente terminou em pergunta? */
    private function deixamosPergunta(WhatsAppConversation $conversation, WhatsAppMessage $inbound): bool
    {
        $ultima = $conversation->messages()
            ->where('direction', 'outbound')
            ->where('id', '<', $inbound->id)
            ->where('type', '!=', 'reaction')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'body', 'payload'])
            ->first(fn (WhatsAppMessage $m) => ! ($m->payload['interim'] ?? false));

        return $ultima !== null && str_contains((string) $ultima->body, '?');
    }

    private function encerrar(WhatsAppConversation $conversation, WhatsAppMessage $inbound): void
    {
        $inbound->update(['payload' => array_merge($inbound->payload ?? [], ['manuela_encerrou' => true])]);

        // Pendência do time (ficha de cancelamento, alerta) continua aberta:
        // só sai da fila o que não precisa de ninguém.
        if (! $conversation->fresh()->needs_human) {
            $conversation->update(['status' => 'resolved']);
        }

        Log::info('whatsapp.manuela_encerrou', ['conversation_id' => $conversation->id, 'message_id' => $inbound->id]);
    }

    private function alertarAtendimento(WhatsAppConversation $conversation, string $pergunta, string $rascunho): void
    {
        Log::info('whatsapp.manuela_alerta_atendimento', ['conversation_id' => $conversation->id, 'message_id' => $this->inboundMessageId]);

        $admins = User::query()->where('role', User::ROLE_ADMIN)->get();

        if ($admins->isNotEmpty()) {
            rescue(fn () => Notification::send($admins, new ManuelaAlertaAtendimentoNotification($conversation, $pergunta, $rascunho)), report: true);
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

    /** Mensagem automática de outro WhatsApp Business (ausência, boas-vindas) ou repetida. */
    public static function pareceRespostaAutomatica(string $texto, WhatsAppConversation $conversa, WhatsAppMessage $mensagem): bool
    {
        $t = mb_strtolower(trim($texto));
        if ($t === '') {
            return false;
        }

        $padroes = [
            '/deixe (o )?seu recado|deixe se recado|deixe sua mensagem|deixe uma mensagem/u',
            '/(responderemos|atenderemos|retornaremos|entraremos em contato).{0,40}(breve|logo|poss[ií]vel|retorno)/u',
            '/(mensagem|resposta) autom[aá]tica/u',
            '/no momento (n[aã]o (estamos|podemos)|estamos (ausentes|fora))/u',
            '/(nosso|o) hor[aá]rio de atendimento [eé]/u',
            '/(obrigad[oa] por (entrar em )?contat(o|ar)).{0,60}(em breve|logo|retornar)/u',
            '/seja bem[- ]vind[oa] (a|à) (minha|nossa) (loja|empresa)/u',
        ];
        foreach ($padroes as $padrao) {
            if (preg_match($padrao, $t)) {
                return true;
            }
        }

        // O mesmo texto (com mais de 20 letras) já veio do cliente antes: é robô repetindo.
        return mb_strlen($t) > 20 && $conversa->messages()
            ->where('direction', 'inbound')
            ->where('id', '<', $mensagem->id)
            ->where('created_at', '>=', now()->subDays(2))
            ->pluck('body')
            ->contains(fn ($b) => mb_strtolower(trim((string) $b)) === $t);
    }
}
