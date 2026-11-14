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
        ?string $replyToWaId = null,
    ): WhatsAppMessage {
        // Responder (pedido do Lira 04/11): guarda o wamid citado para a tela
        // mostrar a citação no balão; a Meta recebe como "context".
        if (filled($replyToWaId)) {
            $extraPayload['responde_a'] = $replyToWaId;
        }

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
            $response = $this->client->paraLoja($conversation->loja)->sendText($conversation->wa_id, $body, $replyToWaId);

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
     * Reação do atendente a uma mensagem do cliente (pedido do Lira 04/11).
     * Fica gravada como mensagem do tipo "reaction" (igual à que chega pelo
     * webhook); a tela junta no balão da mensagem reagida. Emoji vazio = tirou.
     */
    public function sendReaction(
        WhatsAppConversation $conversation,
        WhatsAppMessage $target,
        string $emoji,
        string $sentBy,
        ?int $userId = null,
    ): WhatsAppMessage {
        $basePayload = ['reaction' => ['message_id' => $target->wa_message_id, 'emoji' => $emoji]];

        $message = WhatsAppMessage::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'type' => 'reaction',
            'body' => null,
            'status' => 'pending',
            'sent_by' => $sentBy,
            'user_id' => $userId,
            'payload' => $basePayload,
            'sent_at' => now(),
        ]);

        // Tirar a reação não muda a prévia da lista (o WhatsApp também não mostra).
        if ($emoji !== '') {
            $conversation->registerMessage($message);
        }

        if (! $this->settings->isReadyToSend($conversation->loja)) {
            $message->update(['status' => 'draft_no_token']);

            return $message;
        }

        try {
            $response = $this->client->paraLoja($conversation->loja)->sendReaction($conversation->wa_id, (string) $target->wa_message_id, $emoji);

            $message->update([
                'wa_message_id' => $response['messages'][0]['id'] ?? null,
                'status' => 'sent',
                'payload' => array_merge($basePayload, ['meta_response' => $response]),
            ]);
        } catch (Throwable $exception) {
            Log::warning('whatsapp_reaction_failed', ['conversation_id' => $conversation->id, 'error' => $exception->getMessage()]);

            $message->update([
                'status' => 'failed',
                'payload' => array_merge($basePayload, ['error' => $exception->getMessage()]),
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
        bool $voz = false,
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
            $response = $this->client->paraLoja($conversation->loja)->sendMedia($conversation->wa_id, $type, $mediaId, $caption, $fileName, $voz);

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

    /**
     * Modelo aprovado mandado pelo atendente na conversa (pedido do Lira
     * 11/11/2026): o jeito de falar com o cliente fora da janela de 24h.
     * No histórico fica o texto do modelo já preenchido.
     *
     * @param  array<string, mixed>  $modelo  WhatsAppModelos::montar()
     * @param  array<string, string>  $valores  variável do corpo => valor
     * @param  array<int, string>  $botoes  índice do botão de link => sufixo da URL
     */
    public function sendTemplate(
        WhatsAppConversation $conversation,
        array $modelo,
        array $valores,
        array $botoes,
        ?string $cabecalhoLink,
        string $sentBy,
        ?int $userId = null,
    ): WhatsAppMessage {
        $basePayload = ['template' => $modelo['nome'], 'idioma' => $modelo['idioma'], 'variaveis' => $valores];
        $formato = $modelo['cabecalho']['formato'] ?? null;
        $tipoCabecalho = in_array($formato, ['IMAGE', 'VIDEO', 'DOCUMENT'], true) ? strtolower($formato) : null;

        $message = WhatsAppMessage::query()->create([
            'conversation_id' => $conversation->id,
            'direction' => 'outbound',
            'type' => 'template',
            'body' => WhatsAppModelos::texto($modelo, $valores),
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

        // Variável numerada vai em ordem ({{1}}, {{2}}...); nomeada vai com o nome.
        $parametros = ($modelo['nomeadas'] ?? false)
            ? $valores
            : array_values(array_map(fn (string $chave) => $valores[$chave] ?? '', $modelo['variaveis']));

        try {
            $response = $this->client->paraLoja($conversation->loja)->sendTemplate(
                $conversation->wa_id,
                $modelo['nome'],
                $modelo['idioma'],
                $tipoCabecalho,
                null,
                $parametros,
                $botoes,
                [],
                $tipoCabecalho ? $cabecalhoLink : null,
            );

            $message->update([
                'wa_message_id' => $response['messages'][0]['id'] ?? null,
                'status' => 'sent',
                'payload' => array_merge($basePayload, ['meta_response' => $response]),
            ]);
        } catch (Throwable $exception) {
            Log::warning('whatsapp_template_send_failed', ['conversation_id' => $conversation->id, 'template' => $modelo['nome'], 'error' => $exception->getMessage()]);

            $message->update([
                'status' => 'failed',
                'payload' => array_merge($basePayload, ['error' => $exception->getMessage()]),
            ]);
        }

        return $message;
    }
}
