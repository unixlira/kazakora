<?php

namespace App\Modules\WhatsApp\Services;

use App\Modules\WhatsApp\Support\WhatsAppNumeros;
use App\Modules\WhatsApp\Support\WhatsAppSettings;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WhatsAppCloudApiClient
{
    /** Loja dona do número que manda (services.whatsapp.numeros). */
    private string $loja = WhatsAppNumeros::KAZAKORA;

    public function __construct(private readonly WhatsAppSettings $settings)
    {
    }

    /** Cópia que manda pelo número da loja (a conversa responde sempre pelo número dela). */
    public function paraLoja(?string $loja): static
    {
        $copia = clone $this;
        $copia->loja = WhatsAppNumeros::normalizar($loja);

        return $copia;
    }

    public function loja(): string
    {
        return $this->loja;
    }

    /**
     * @param  string|null  $replyToWaId  wamid da mensagem citada (Responder,
     *                                    pedido do Lira 04/11): vira o "context" da Cloud API
     */
    public function sendText(string $to, string $message, ?string $replyToWaId = null): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $message,
            ],
        ];

        if (filled($replyToWaId)) {
            $payload['context'] = ['message_id' => $replyToWaId];
        }

        return $this->postMessage($payload);
    }

    /**
     * @param  list<string>  $bodyParams  valores de {{1}}, {{2}}... do corpo
     * @param  array<int, string>  $urlButtonParams  índice do botão => sufixo da URL dinâmica
     * @param  array<int, string>  $quickReplyPayloads  índice do botão de resposta rápida => payload que volta no webhook
     * @param  string|null  $headerMediaLink  mídia do cabeçalho por URL pública (alternativa ao ID)
     */
    public function sendTemplate(
        string $to,
        string $templateName,
        string $languageCode = 'pt_BR',
        ?string $headerMediaType = null,
        ?string $headerMediaId = null,
        array $bodyParams = [],
        array $urlButtonParams = [],
        array $quickReplyPayloads = [],
        ?string $headerMediaLink = null,
    ): array {
        $template = [
            'name' => $templateName,
            'language' => ['code' => $languageCode],
        ];

        $components = [];

        if ($headerMediaType && ($headerMediaId || $headerMediaLink)) {
            $components[] = [
                'type' => 'header',
                'parameters' => [[
                    'type' => $headerMediaType,
                    $headerMediaType => $headerMediaId ? ['id' => $headerMediaId] : ['link' => $headerMediaLink],
                ]],
            ];
        }

        if ($bodyParams !== []) {
            $components[] = [
                'type' => 'body',
                // Chave texto = modelo com variável nomeada ({{nome}}): vai o parameter_name.
                'parameters' => array_values(array_map(
                    fn ($value, $key) => is_string($key)
                        ? ['type' => 'text', 'parameter_name' => $key, 'text' => (string) $value]
                        : ['type' => 'text', 'text' => (string) $value],
                    $bodyParams,
                    array_keys($bodyParams),
                )),
            ];
        }

        foreach ($urlButtonParams as $index => $value) {
            $components[] = [
                'type' => 'button',
                'sub_type' => 'url',
                'index' => (string) $index,
                'parameters' => [['type' => 'text', 'text' => (string) $value]],
            ];
        }

        foreach ($quickReplyPayloads as $index => $payload) {
            $components[] = [
                'type' => 'button',
                'sub_type' => 'quick_reply',
                'index' => (string) $index,
                'parameters' => [['type' => 'payload', 'payload' => (string) $payload]],
            ];
        }

        if ($components !== []) {
            $template['components'] = $components;
        }

        return $this->postMessage([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => $template,
        ]);
    }

    public function uploadMedia(string $absolutePath, string $mimeType): string
    {
        $this->garantirPronto();

        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new RuntimeException('Arquivo de mídia não encontrado para upload no WhatsApp.');
        }

        $handle = fopen($absolutePath, 'r');
        if ($handle === false) {
            throw new RuntimeException('Não foi possível abrir a mídia para upload no WhatsApp.');
        }

        try {
            $response = Http::withToken((string) WhatsAppNumeros::accessToken($this->loja))
                ->acceptJson()
                ->attach('file', $handle, basename($absolutePath), ['Content-Type' => $mimeType])
                ->post($this->graphUrl('media'), [
                    'messaging_product' => 'whatsapp',
                    // A Meta pede o tipo também como campo do formulário, não só no arquivo.
                    'type' => $mimeType,
                ]);
        } finally {
            fclose($handle);
        }

        if ($response->failed()) {
            throw new RuntimeException('Falha ao subir mídia no WhatsApp: '.$response->body());
        }

        $mediaId = $response->json('id');
        if (! filled($mediaId)) {
            throw new RuntimeException('Upload de mídia no WhatsApp não retornou ID.');
        }

        return (string) $mediaId;
    }

    /** $voz: áudio gravado pelo atendente (ogg/opus do ConversorDeVoz) vai como nota de voz. */
    public function sendMedia(string $to, string $mediaType, string $mediaId, ?string $caption = null, ?string $filename = null, bool $voz = false): array
    {
        if (! in_array($mediaType, ['image', 'video', 'audio', 'document'], true)) {
            throw new RuntimeException('Tipo de mídia inválido para WhatsApp.');
        }

        $media = ['id' => $mediaId];
        // Áudio não aceita legenda na API.
        if (filled($caption) && $mediaType !== 'audio') {
            $media['caption'] = $caption;
        }
        // Nota de voz: sem isto o áudio gravado chegava e não baixava (14/11/2026).
        if ($voz && $mediaType === 'audio') {
            $media['voice'] = true;
        }
        // Sem filename o cliente recebe o documento como "Untitled".
        if (filled($filename) && $mediaType === 'document') {
            $media['filename'] = $filename;
        }

        return $this->postMessage([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => $mediaType,
            $mediaType => $media,
        ]);
    }

    /**
     * Reação com emoji a uma mensagem (pedido do Lira 04/11). Emoji vazio
     * tira a reação, igual ao WhatsApp.
     */
    public function sendReaction(string $to, string $waMessageId, string $emoji): array
    {
        return $this->postMessage([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'reaction',
            'reaction' => [
                'message_id' => $waMessageId,
                'emoji' => $emoji,
            ],
        ]);
    }

    private function postMessage(array $payload): array
    {
        $this->garantirPronto();

        $response = Http::withToken((string) WhatsAppNumeros::accessToken($this->loja))
            ->acceptJson()
            ->post($this->graphUrl('messages'), $payload);

        if ($response->failed()) {
            throw new RuntimeException('Falha ao enviar WhatsApp: '.$response->body());
        }

        return $response->json() ?? [];
    }

    private function garantirPronto(): void
    {
        if (! $this->settings->isReadyToSend($this->loja)) {
            throw new RuntimeException($this->loja === WhatsAppNumeros::KAZAKORA
                ? 'WhatsApp ainda não tem WHATSAPP_ACCESS_TOKEN e WHATSAPP_PHONE_NUMBER_ID configurados no servidor.'
                : 'WhatsApp da '.WhatsAppNumeros::nome($this->loja).' ainda não tem número configurado no servidor.');
        }
    }

    private function graphUrl(string $edge): string
    {
        $baseUrl = rtrim(config('services.whatsapp.graph_url', 'https://graph.facebook.com/v20.0'), '/');
        $phoneNumberId = WhatsAppNumeros::phoneNumberId($this->loja);

        return "{$baseUrl}/{$phoneNumberId}/{$edge}";
    }
}
