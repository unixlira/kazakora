<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WhatsApp\Jobs\ReplyWithManuela;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use App\Modules\WhatsApp\Services\ConversorDeVoz;
use App\Modules\WhatsApp\Services\ManuelaAgentClient;
use App\Modules\WhatsApp\Services\WhatsAppMediaDownloader;
use App\Modules\WhatsApp\Services\WhatsAppOutbox;
use App\Modules\WhatsApp\Support\WhatsAppSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * Tela "Conversas" (pedido explícito 2026-10-08): todas as conversas do
 * WhatsApp oficial num layout igual ao do WhatsApp Web, atualizando sozinha.
 *
 * "Tempo real" é polling de 3s em /atualizacoes: a hospedagem compartilhada
 * não segura websocket (Reverb/Pusher) nem SSE com processo preso. A consulta
 * é leve: só o que mudou desde o último `since`, usando updated_at.
 */
class WhatsAppInboxController extends Controller
{
    private const CONVERSATION_PAGE = 300;

    private const MESSAGE_PAGE = 80;

    private const DOCUMENT_MAX_KB = 60 * 1024;

    // Formatos que o WhatsApp mostra como foto/vídeo/áudio; o resto vai como documento.
    private const IMAGE_TYPES = ['image/jpeg', 'image/png'];

    private const VIDEO_TYPES = ['video/mp4', 'video/3gpp'];

    private const AUDIO_TYPES = ['audio/aac', 'audio/mp4', 'audio/mpeg', 'audio/amr', 'audio/ogg'];

    public function index(WhatsAppSettings $settings, ManuelaAgentClient $agent): Response
    {
        return Inertia::render('Admin/WhatsApp/Inbox', [
            'conversations' => $this->conversationQuery()->limit(self::CONVERSATION_PAGE)->get()->map(fn ($c) => $this->conversationPayload($c)),
            'serverTime' => now()->toISOString(),
            'status' => [
                'readyToSend' => $settings->isReadyToSend(),
                'enabled' => $settings->bool('enabled'),
                'autoReply' => $settings->bool('auto_reply_enabled'),
                'manuelaRemote' => $agent->isConfigured(),
                'attendantName' => $settings->get('attendant_name'),
                'brandName' => $settings->get('brand_name'),
            ],
        ]);
    }

    public function messages(Request $request, WhatsAppConversation $conversation): JsonResponse
    {
        $query = $conversation->messages()
            ->whereIn('direction', ['inbound', 'outbound'])
            ->orderByDesc('id')
            ->limit(self::MESSAGE_PAGE);

        if ($request->filled('before')) {
            $query->where('id', '<', (int) $request->query('before'));
        }

        $messages = $query->get()->reverse()->values();

        return response()->json([
            'conversation' => $this->conversationPayload($conversation),
            'messages' => $messages->map(fn ($m) => $this->messagePayload($m)),
            'hasMore' => $messages->count() === self::MESSAGE_PAGE,
        ]);
    }

    public function updates(Request $request): JsonResponse
    {
        $now = now();
        // 2s de folga: mensagem gravada no mesmo segundo do último poll não se perde
        // (o front deduplica por id).
        $since = $request->filled('since')
            // O front manda ISO em UTC; o banco grava no fuso do app.
            ? Carbon::parse($request->query('since'))->setTimezone(config('app.timezone'))->subSeconds(2)
            : $now->copy()->subMinute();

        $conversations = $this->conversationQuery()
            ->where('updated_at', '>=', $since)
            ->limit(self::CONVERSATION_PAGE)
            ->get();

        $messages = collect();
        if ($request->filled('conversation')) {
            $messages = WhatsAppMessage::query()
                ->where('conversation_id', (int) $request->query('conversation'))
                ->whereIn('direction', ['inbound', 'outbound'])
                ->where('updated_at', '>=', $since)
                ->orderBy('id')
                ->limit(200)
                ->get();
        }

        return response()->json([
            'serverTime' => $now->toISOString(),
            'conversations' => $conversations->map(fn ($c) => $this->conversationPayload($c)),
            'messages' => $messages->map(fn ($m) => $this->messagePayload($m)),
        ]);
    }

    /**
     * Avisos de mensagem nova no admin inteiro (toasts no canto superior
     * direito, ver WhatsAppToasts.vue). Sem `after`, só devolve o último id
     * pra não despejar mensagem antiga quando a pessoa abre o painel.
     */
    public function incoming(Request $request): JsonResponse
    {
        $unread = WhatsAppConversation::query()->where('unread_count', '>', 0)->count();

        if (! $request->filled('after')) {
            return response()->json([
                'lastId' => (int) WhatsAppMessage::query()->max('id'),
                'messages' => [],
                'unreadConversations' => $unread,
            ]);
        }

        $messages = WhatsAppMessage::query()
            ->with('conversation:id,profile_name,phone,wa_id')
            ->where('id', '>', (int) $request->query('after'))
            ->where('direction', 'inbound')
            ->orderBy('id')
            ->limit(10)
            ->get();

        return response()->json([
            'lastId' => max((int) $request->query('after'), (int) WhatsAppMessage::query()->max('id')),
            'unreadConversations' => $unread,
            'messages' => $messages->map(fn (WhatsAppMessage $m) => [
                'id' => $m->id,
                'conversationId' => $m->conversation_id,
                'name' => $m->conversation?->profile_name,
                'phone' => $m->conversation?->phone ?? $m->conversation?->wa_id,
                'preview' => $m->preview(),
                'at' => ($m->received_at ?? $m->created_at)?->toISOString(),
            ]),
        ]);
    }

    public function send(Request $request, WhatsAppConversation $conversation, WhatsAppOutbox $outbox): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:4096'],
        ]);

        $this->humanTakesOver($conversation);

        $message = $outbox->sendText($conversation, $validated['body'], $request->user()->name, $request->user()->id);

        return response()->json([
            'message' => $this->messagePayload($message),
            'conversation' => $this->conversationPayload($conversation->fresh()),
        ]);
    }

    /**
     * Nota de voz gravada na tela (`gravacao`) ou arquivo anexado (clipe).
     * Limites da Meta: imagem 5 MB, áudio e vídeo 16 MB, documento 100 MB
     * (aqui 60 MB, abaixo do client_max_body_size de 64M do nginx).
     */
    public function sendMedia(Request $request, WhatsAppConversation $conversation, WhatsAppOutbox $outbox, ConversorDeVoz $conversor): JsonResponse
    {
        // Validator manual: no admin o Laravel só responde JSON em api/*; um
        // ValidationException aqui viraria redirect e a tela não veria o motivo.
        $validator = Validator::make($request->all(), [
            'arquivo' => ['required', 'file', 'max:'.self::DOCUMENT_MAX_KB],
            'gravacao' => ['sometimes', 'boolean'],
            'legenda' => ['nullable', 'string', 'max:1024'],
        ], [
            'arquivo.required' => 'Escolha um arquivo.',
            'arquivo.max' => 'Arquivo grande demais: o limite é 60 MB.',
            'arquivo.uploaded' => 'O arquivo não chegou ao servidor (grande demais?).',
            'legenda.max' => 'Legenda grande demais: até 1024 caracteres.',
        ]);
        if ($validator->fails()) {
            return $this->mediaError($validator->errors()->first());
        }
        $validated = $validator->validated();

        $file = $request->file('arquivo');
        $caption = filled($validated['legenda'] ?? null) ? trim($validated['legenda']) : null;
        $temporary = null;

        // Gravar áudio é coisa do atendente humano: com a Manuela atendendo,
        // a tela deixa o microfone desligado e o servidor recusa também.
        if ($request->boolean('gravacao') && $conversation->ai_enabled) {
            return $this->mediaError('Áudio gravado só no modo humano: desligue a chave da Manuela para gravar.');
        }

        if ($request->boolean('gravacao')) {
            try {
                $temporary = $conversor->paraOgg($file->getRealPath());
            } catch (Throwable $exception) {
                Log::warning('whatsapp_voice_convert_failed', ['conversation_id' => $conversation->id, 'error' => $exception->getMessage()]);

                return $this->mediaError('Não deu para converter o áudio gravado. Tente de novo.');
            }
            [$type, $path, $mime, $name, $caption] = ['audio', $temporary, 'audio/ogg', 'audio.ogg', null];
        } else {
            $mime = $file->getMimeType() ?: 'application/octet-stream';
            $size = $file->getSize();
            $type = match (true) {
                in_array($mime, self::IMAGE_TYPES, true) && $size <= 5 * 1024 * 1024 => 'image',
                in_array($mime, self::VIDEO_TYPES, true) && $size <= 16 * 1024 * 1024 => 'video',
                in_array($mime, self::AUDIO_TYPES, true) && $size <= 16 * 1024 * 1024 && ! $caption => 'audio',
                default => 'document',
            };
            $path = $file->getRealPath();
            $name = mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), -200) ?: 'arquivo';
        }

        if ($type === 'audio' && filesize($path) > 16 * 1024 * 1024) {
            if ($temporary) {
                @unlink($temporary);
            }

            return $this->mediaError('Áudio grande demais: o WhatsApp aceita até 16 MB.');
        }

        $this->humanTakesOver($conversation);

        try {
            $message = $outbox->sendMedia($conversation, $type, $path, $mime, $request->user()->name, $request->user()->id, $caption, $name);
        } finally {
            if ($temporary) {
                @unlink($temporary);
            }
        }

        return response()->json([
            'message' => $this->messagePayload($message),
            'conversation' => $this->conversationPayload($conversation->fresh()),
        ]);
    }

    private function mediaError(string $message): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => ['arquivo' => [$message]]], 422);
    }

    /** Pessoa respondeu: a Manuela sai da conversa até alguém devolver, e o alerta de "precisa de humano" está atendido. */
    private function humanTakesOver(WhatsAppConversation $conversation): void
    {
        $conversation->update([
            'ai_enabled' => false,
            'unread_count' => 0,
            'needs_human' => false,
            'status' => $conversation->status === 'needs_human' ? 'open' : $conversation->status,
        ]);
    }

    public function markRead(WhatsAppConversation $conversation): JsonResponse
    {
        if ($conversation->unread_count > 0) {
            $conversation->update(['unread_count' => 0]);
        }

        return response()->json(['conversation' => $this->conversationPayload($conversation)]);
    }

    public function toggleManuela(Request $request, WhatsAppConversation $conversation): JsonResponse
    {
        $validated = $request->validate(['ai_enabled' => ['required', 'boolean']]);

        $wasEnabled = (bool) $conversation->ai_enabled;

        // Mexer na chave resolve o alerta de "precisa de humano" nos dois
        // sentidos: humano assumiu (pedido 2026-10-08: "após ticar a flag com
        // humano, remover a badge vermelha") ou devolveu pra Manuela.
        $conversation->update([
            'ai_enabled' => $validated['ai_enabled'],
            'needs_human' => false,
            'status' => $conversation->status === 'needs_human' ? 'open' : $conversation->status,
        ]);

        // Ligou a chave com o cliente esperando resposta: a Manuela responde
        // a última mensagem dele na hora (dentro da janela de 24h da Meta).
        if (! $wasEnabled && $conversation->ai_enabled && $conversation->insideServiceWindow()) {
            $last = $conversation->messages()->whereIn('direction', ['inbound', 'outbound'])->orderByDesc('id')->first();

            if ($last?->direction === 'inbound') {
                ReplyWithManuela::dispatch($conversation->id, $last->id)->afterResponse();
            }
        }

        return response()->json(['conversation' => $this->conversationPayload($conversation->fresh())]);
    }

    /** Apaga a conversa e todas as mensagens dela (só deste lado: o cliente continua com o histórico no celular). */
    public function destroy(WhatsAppConversation $conversation): JsonResponse
    {
        DB::transaction(function () use ($conversation) {
            $conversation->messages()->delete();
            $conversation->delete();
        });
        Storage::disk('local')->deleteDirectory('whatsapp/enviadas/'.$conversation->id);

        return response()->json(['deleted' => $conversation->id]);
    }

    public function updateStatus(Request $request, WhatsAppConversation $conversation): JsonResponse
    {
        $validated = $request->validate(['status' => ['required', 'in:open,resolved']]);

        $conversation->update([
            'status' => $validated['status'],
            'needs_human' => $validated['status'] === 'resolved' ? false : $conversation->needs_human,
            'unread_count' => 0,
        ]);

        return response()->json(['conversation' => $this->conversationPayload($conversation->fresh())]);
    }

    /**
     * Mídia recebida (foto, áudio, vídeo, documento) só existe na Meta: o ID
     * vira uma URL temporária que exige o token. Proxy aqui pra tela mostrar
     * sem expor o token pro navegador.
     */
    public function media(WhatsAppMessage $message, WhatsAppMediaDownloader $downloader): HttpResponse
    {
        $headers = ['Cache-Control' => 'private, max-age=86400'];
        $fileName = $message->payload['document']['filename'] ?? null;
        if ($fileName) {
            $headers['Content-Disposition'] = HeaderUtils::makeDisposition('inline', $fileName, Str::ascii($fileName) ?: 'documento');
        }

        // Enviado pela tela: cópia local, sem ir à Meta.
        $local = $message->localMediaPath();
        if ($local && Storage::disk('local')->exists($local)) {
            return Storage::disk('local')->response($local, null, $headers + [
                'Content-Type' => $message->payload[$message->type]['mime_type'] ?? Storage::disk('local')->mimeType($local),
            ]);
        }

        $file = $downloader->download($message);
        abort_unless($file, 404);

        return response($file['body'], 200, $headers + ['Content-Type' => $file['mime_type']]);
    }

    private function conversationQuery()
    {
        return WhatsAppConversation::query()
            ->whereNotNull('last_message_preview')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id');
    }

    private function conversationPayload(WhatsAppConversation $c): array
    {
        return [
            'id' => $c->id,
            'waId' => $c->wa_id,
            'name' => $c->profile_name,
            'phone' => $c->phone ?? $c->wa_id,
            'status' => $c->status,
            'needsHuman' => (bool) $c->needs_human,
            'aiEnabled' => (bool) $c->ai_enabled,
            'unread' => (int) $c->unread_count,
            'preview' => $c->last_message_preview,
            'previewDirection' => $c->last_message_direction,
            'lastMessageAt' => $c->last_message_at?->toISOString(),
            'insideWindow' => $c->insideServiceWindow(),
        ];
    }

    private function messagePayload(WhatsAppMessage $m): array
    {
        $payload = $m->payload ?? [];

        return [
            'id' => $m->id,
            'direction' => $m->direction,
            'type' => $m->type,
            'body' => $m->body,
            'status' => $m->status,
            'sentBy' => $m->sent_by,
            'at' => ($m->received_at ?? $m->sent_at ?? $m->created_at)?->toISOString(),
            'hasMedia' => $m->hasMedia(),
            'mimeType' => $payload[$m->type]['mime_type'] ?? null,
            'fileName' => $payload['document']['filename'] ?? null,
            'transcription' => $payload['transcription'] ?? null,
            'error' => $m->status === 'failed' ? ($payload['error'] ?? null) : null,
        ];
    }
}
