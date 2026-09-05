<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use App\Modules\WhatsApp\Services\ManuelaAutoReplyService;
use App\Modules\WhatsApp\Services\WhatsAppCloudApiClient;
use App\Modules\WhatsApp\Support\WhatsAppSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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

    public function handle(
        Request $request,
        WhatsAppSettings $settings,
        ManuelaAutoReplyService $manuela,
        WhatsAppCloudApiClient $client,
    ): JsonResponse {
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
                    $conversation = $this->storeInboundMessage($message, $contact, $payload);
                    $handled++;

                    if ($settings->bool('enabled') && $settings->bool('auto_reply_enabled') && ! $conversation->needs_human) {
                        $this->autoReply($conversation, $message, $manuela, $client, $settings);
                    }
                }
            }
        }

        return response()->json(['status' => 'ok', 'handled' => $handled]);
    }

    private function storeInboundMessage(array $message, array $contact, array $payload): WhatsAppConversation
    {
        $body = $message['text']['body'] ?? $message['button']['text'] ?? $message['interactive']['button_reply']['title'] ?? null;
        $receivedAt = isset($message['timestamp']) ? Carbon::createFromTimestamp((int) $message['timestamp']) : now();
        $waId = $message['from'];

        $conversation = WhatsAppConversation::query()->updateOrCreate(
            ['wa_id' => $waId],
            [
                'phone' => $waId,
                'profile_name' => $contact['profile']['name'] ?? null,
                'last_message_at' => $receivedAt,
                'last_customer_message_at' => $receivedAt,
                'metadata' => ['last_payload_object' => $payload['object'] ?? null],
            ],
        );

        WhatsAppMessage::query()->firstOrCreate(
            ['wa_message_id' => $message['id'] ?? null],
            [
                'conversation_id' => $conversation->id,
                'direction' => 'inbound',
                'type' => $message['type'] ?? 'unknown',
                'body' => $body,
                'status' => 'received',
                'payload' => $message,
                'received_at' => $receivedAt,
            ],
        );

        return $conversation->fresh();
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

    private function autoReply(
        WhatsAppConversation $conversation,
        array $message,
        ManuelaAutoReplyService $manuela,
        WhatsAppCloudApiClient $client,
        WhatsAppSettings $settings,
    ): void {
        $body = $message['text']['body'] ?? null;

        if (! filled($body)) {
            $conversation->update(['needs_human' => true, 'status' => 'needs_human']);
            return;
        }

        $reply = $manuela->buildReply($conversation, $body);

        if ($reply['needs_human'] ?? false) {
            $conversation->update(['needs_human' => true, 'status' => 'needs_human']);
        }

        if (! $settings->isReadyToSend()) {
            WhatsAppMessage::query()->create([
                'conversation_id' => $conversation->id,
                'direction' => 'outbound',
                'type' => 'text',
                'body' => $reply['reply'],
                'status' => 'draft_no_token',
                'payload' => $reply,
            ]);
            return;
        }

        try {
            $response = $client->sendText($conversation->wa_id, $reply['reply']);
            $waMessageId = $response['messages'][0]['id'] ?? null;

            WhatsAppMessage::query()->create([
                'conversation_id' => $conversation->id,
                'wa_message_id' => $waMessageId,
                'direction' => 'outbound',
                'type' => 'text',
                'body' => $reply['reply'],
                'status' => 'sent',
                'payload' => ['manuela' => $reply, 'meta_response' => $response],
                'sent_at' => now(),
            ]);

            $conversation->update(['last_auto_reply_at' => now(), 'last_message_at' => now()]);
        } catch (Throwable $exception) {
            Log::warning('whatsapp_auto_reply_failed', ['conversation_id' => $conversation->id, 'error' => $exception->getMessage()]);

            WhatsAppMessage::query()->create([
                'conversation_id' => $conversation->id,
                'direction' => 'outbound',
                'type' => 'text',
                'body' => $reply['reply'],
                'status' => 'failed',
                'payload' => ['manuela' => $reply, 'error' => $exception->getMessage()],
            ]);
        }
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
