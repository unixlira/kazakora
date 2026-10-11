<?php

namespace App\Modules\WhatsApp\Services;

use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use App\Modules\WhatsApp\Support\WhatsAppSettings;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Toda mensagem que sai pro cliente (Manuela ou atendente) passa por aqui:
 * grava no histórico, manda pela Cloud API e atualiza a lista de conversas.
 * Sem credencial da Meta, a mensagem fica gravada como "draft_no_token" —
 * aparece no chat com relógio, pra dar pra testar a tela antes do token.
 */
class WhatsAppOutbox
{
    public function __construct(
        private readonly WhatsAppCloudApiClient $client,
        private readonly WhatsAppSettings $settings,
    ) {
    }

    public function sendText(
        WhatsAppConversation $conversation,
        string $body,
        string $sentBy,
        ?int $userId = null,
        array $extraPayload = [],
    ): WhatsAppMessage {
        $message = WhatsAppMessage::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'type' => 'text',
            'body' => $body,
            'status' => 'pending',
            'sent_by' => $sentBy,
            'user_id' => $userId,
            'payload' => $extraPayload,
            'sent_at' => now(),
        ]);

        $conversation->registerMessage($message);

        if (! $this->settings->isReadyToSend()) {
            $message->update(['status' => 'draft_no_token']);

            return $message;
        }

        try {
            $response = $this->client->sendText($conversation->wa_id, $body);

            $message->update([
                'wa_message_id' => $response['messages'][0]['id'] ?? null,
                'status' => 'sent',
                'payload' => array_merge($extraPayload, ['meta_response' => $response]),
            ]);
        } catch (Throwable $exception) {
            Log::warning('whatsapp_send_failed', ['conversation_id' => $conversation->id, 'error' => $exception->getMessage()]);

            $message->update([
                'status' => 'failed',
                'payload' => array_merge($extraPayload, ['error' => $exception->getMessage()]),
            ]);
        }

        return $message;
    }
}
