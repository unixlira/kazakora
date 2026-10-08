<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\WhatsApp\Jobs\ReplyWithManuela;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use App\Modules\WhatsApp\Services\ManuelaAgentClient;
use App\Modules\WhatsApp\Services\WhatsAppOutbox;
use App\Modules\WhatsApp\Support\WhatsAppSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

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

        // Pessoa respondeu: a Manuela sai da conversa até alguém devolver.
        $conversation->update(['ai_enabled' => false, 'unread_count' => 0]);

        $message = $outbox->sendText($conversation, $validated['body'], $request->user()->name, $request->user()->id);

        return response()->json([
            'message' => $this->messagePayload($message),
            'conversation' => $this->conversationPayload($conversation->fresh()),
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

        $conversation->update([
            'ai_enabled' => $validated['ai_enabled'],
            // Devolver pra Manuela tira o alerta de "precisa de humano".
            'needs_human' => $validated['ai_enabled'] ? false : $conversation->needs_human,
            'status' => $validated['ai_enabled'] && $conversation->status === 'needs_human' ? 'open' : $conversation->status,
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
    public function media(WhatsAppMessage $message): HttpResponse
    {
        $mediaId = $message->mediaId();
        $token = config('services.whatsapp.access_token');

        abort_unless($mediaId && filled($token), 404);

        $baseUrl = rtrim(config('services.whatsapp.graph_url', 'https://graph.facebook.com/v20.0'), '/');
        $meta = Http::withToken($token)->acceptJson()->timeout(20)->get("{$baseUrl}/{$mediaId}");
        abort_if($meta->failed() || ! $meta->json('url'), 404);

        $file = Http::withToken($token)->timeout(60)->get($meta->json('url'));
        abort_if($file->failed(), 404);

        return response($file->body(), 200, [
            'Content-Type' => $meta->json('mime_type') ?: $file->header('Content-Type') ?: 'application/octet-stream',
            'Cache-Control' => 'private, max-age=86400',
        ]);
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
            'hasMedia' => $m->mediaId() !== null,
            'mimeType' => $payload[$m->type]['mime_type'] ?? null,
            'fileName' => $payload['document']['filename'] ?? null,
            'error' => $m->status === 'failed' ? ($payload['error'] ?? null) : null,
        ];
    }
}
