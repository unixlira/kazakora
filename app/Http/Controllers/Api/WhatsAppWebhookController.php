<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\WhatsApp\Jobs\ReplyWithManuela;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use App\Modules\WhatsApp\Support\WhatsAppSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class WhatsAppWebhookController extends Controller
{
    public function verify(Request $request, WhatsAppSettings $settings): Response
    {
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $token = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));

        if ($mode === 'subscribe' && hash_equals($settings->ensureVerifyToken(), (string) $token)) {
            return response((string) $challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Invalid verify token', 403)->header('Content-Type', 'text/plain');
    }

    public function handle(Request $request, WhatsAppSettings $settings): JsonResponse
    {
        if (! $this->signatureIsValid($request)) {
            return response()->json(['error' => 'invalid_signature'], 403);
        }

        $payload = $request->all();
        $handled = 0;

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                foreach ($value['statuses'] ?? [] as $status) {
                    $this->storeStatus($status, $payload);
                    $handled++;
                }

                foreach ($value['messages'] ?? [] as $message) {
                    $contact = collect($value['contacts'] ?? [])->firstWhere('wa_id', $message['from'] ?? null) ?? [];
                    [$conversation, $stored] = $this->storeInboundMessage($message, $contact, $payload, $settings);
                    $handled++;

                    // A chave da conversa é quem manda (pedido 2026-10-08: "a Manu
                    // não está respondendo depois de ticar a chave"). Reentrega
                    // da Meta (mesmo wa_message_id) não responde de novo.
                    if ($stored->wasRecentlyCreated && $conversation->ai_enabled) {
                        ReplyWithManuela::dispatch($conversation->id, $stored->id)->afterResponse();
                    }
                }
            }
        }

        return response()->json(['status' => 'ok', 'handled' => $handled]);
    }

    /** @return array{0: WhatsAppConversation, 1: WhatsAppMessage} */
    private function storeInboundMessage(array $message, array $contact, array $payload, WhatsAppSettings $settings): array
    {
        $type = $message['type'] ?? 'unknown';
        $body = $message['text']['body']
            ?? $message['button']['text']
            ?? $message['interactive']['button_reply']['title']
            ?? $message['interactive']['list_reply']['title']
            ?? $message[$type]['caption']
            ?? $message['document']['filename']
            ?? (isset($message['location']) ? trim(($message['location']['name'] ?? '').' '.($message['location']['address'] ?? '')) ?: null : null);
        $receivedAt = isset($message['timestamp']) ? Carbon::createFromTimestamp((int) $message['timestamp']) : now();
        $waId = $message['from'];

        $conversation = WhatsAppConversation::query()->firstOrNew(['wa_id' => $waId]);
        $conversation->fill([
            'phone' => $waId,
            'profile_name' => $contact['profile']['name'] ?? $conversation->profile_name,
            'last_customer_message_at' => $receivedAt,
            // Mescla: o metadata também guarda o produto que a Manuela abriu.
            'metadata' => array_merge($conversation->metadata ?? [], ['last_payload_object' => $payload['object'] ?? null]),
        ]);
        // Conversa nova já nasce com a chave da Manuela no padrão escolhido
        // em Configurações > "Resposta automática".
        if (! $conversation->exists) {
            $conversation->ai_enabled = $settings->bool('auto_reply_enabled');
        }
        // Conversa encerrada volta pra fila quando o cliente escreve de novo.
        if ($conversation->status === 'resolved') {
            $conversation->status = 'open';
        }
        $conversation->save();

        $stored = WhatsAppMessage::query()->firstOrCreate(
            ['wa_message_id' => $message['id'] ?? null],
            [
                'conversation_id' => $conversation->id,
                'direction' => 'inbound',
                'type' => $type,
                'body' => $body,
                'status' => 'received',
                'payload' => $message,
                'received_at' => $receivedAt,
            ],
        );

        if ($stored->wasRecentlyCreated) {
            $conversation->registerMessage($stored);
        }

        return [$conversation->fresh(), $stored];
    }

    private function storeStatus(array $status, array $payload): void
    {
        $message = WhatsAppMessage::query()->where('wa_message_id', $status['id'] ?? null)->first();

        if ($message) {
            $message->update([
                'status' => $status['status'] ?? $message->status,
                'payload' => array_merge($message->payload ?? [], ['status_payload' => $status]),
            ]);

            return;
        }

        $conversation = WhatsAppConversation::query()->firstOrCreate(
            ['wa_id' => $status['recipient_id'] ?? 'unknown'],
            ['phone' => $status['recipient_id'] ?? null, 'last_message_at' => now()],
        );

        WhatsAppMessage::query()->create([
            'conversation_id' => $conversation->id,
            'wa_message_id' => $status['id'] ?? null,
            'direction' => 'status',
            'type' => 'status',
            'status' => $status['status'] ?? 'unknown',
            'payload' => ['status' => $status, 'webhook' => $payload['object'] ?? null],
            'received_at' => now(),
        ]);
    }

    private function signatureIsValid(Request $request): bool
    {
        $secret = config('services.whatsapp.app_secret');

        if (! filled($secret)) {
            return true;
        }

        $signature = (string) $request->header('X-Hub-Signature-256');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }
}
