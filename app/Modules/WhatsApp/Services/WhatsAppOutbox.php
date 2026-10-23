<?php

namespace App\Modules\WhatsApp\Services;

use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use App\Modules\WhatsApp\Support\WhatsAppSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Toda mensagem que sai pro cliente (Manuela ou atendente) passa por aqui:
 * grava no histórico, manda pela Cloud API e atualiza a lista de conversas.
 * Sem credencial da Meta, a mensagem fica gravada como "draft_no_token" —
 * aparece no chat com relógio, pra dar pra testar a tela antes do token.
 * Sai SEMPRE pelo número da loja da conversa (KazaKora ou FerraKora).
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

        if (! $this->settings->isReadyToSend($conversation->loja)) {
            $message->update(['status' => 'draft_no_token']);

            return $message;
        }

        try {
            $response = $this->client->paraLoja($conversation->loja)->sendText($conversation->wa_id, $body);

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

    /**
     * Arquivo mandado pelo atendente na tela de Conversas (nota de voz gravada
     * ou anexo). A cópia fica em storage/app/private/whatsapp/enviadas para a
     * tela tocar/abrir sem depender da Meta (lá a mídia expira em 30 dias).
     * Igual ao texto: sem credencial vira "draft_no_token"; erro vira "failed".
     *
     * @param  'image'|'video'|'audio'|'document'  $type
     */
    public function sendMedia(
        WhatsAppConversation $conversation,
        string $type,
        string $absolutePath,
        string $mimeType,
        string $sentBy,
        ?int $userId = null,
        ?string $caption = null,
        ?string $fileName = null,
    ): WhatsAppMessage {
        $extension = pathinfo($fileName ?: $absolutePath, PATHINFO_EXTENSION) ?: 'bin';
        $localPath = 'whatsapp/enviadas/'.$conversation->id.'/'.Str::uuid().'.'.strtolower($extension);
        Storage::disk('local')->put($localPath, file_get_contents($absolutePath));

        $media = array_filter(['mime_type' => $mimeType, 'filename' => $type === 'document' ? $fileName : null]);
        $basePayload = ['local_path' => $localPath, $type => $media];

        $message = WhatsAppMessage::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'type' => $type,
            // Igual ao que chega pelo webhook: legenda ou, no documento, o nome do arquivo.
            'body' => filled($caption) ? $caption : ($type === 'document' ? $fileName : null),
            'status' => 'pending',
            'sent_by' => $sentBy,
            'user_id' => $userId,
            'payload' => $basePayload,
            'sent_at' => now(),
        ]);

        $conversation->registerMessage($message);

        if (! $this->settings->isReadyToSend($conversation->loja)) {
            $message->update(['status' => 'draft_no_token']);

            return $message;
        }

        try {
            $mediaId = $this->client->paraLoja($conversation->loja)->uploadMedia(Storage::disk('local')->path($localPath), $mimeType);
            $response = $this->client->paraLoja($conversation->loja)->sendMedia($conversation->wa_id, $type, $mediaId, $caption, $fileName);

            $message->update([
                'wa_message_id' => $response['messages'][0]['id'] ?? null,
                'status' => 'sent',
                'payload' => array_merge($basePayload, [$type => ['id' => $mediaId] + $media, 'meta_response' => $response]),
            ]);
        } catch (Throwable $exception) {
            Log::warning('whatsapp_media_send_failed', ['conversation_id' => $conversation->id, 'type' => $type, 'error' => $exception->getMessage()]);

            $message->update([
                'status' => 'failed',
                'payload' => array_merge($basePayload, ['error' => $exception->getMessage()]),
            ]);
        }

        return $message;
    }
}
