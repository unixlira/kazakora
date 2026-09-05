<?php

namespace App\Modules\WhatsApp\Services;

use App\Modules\WhatsApp\Support\WhatsAppSettings;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WhatsAppCloudApiClient
{
    public function __construct(private readonly WhatsAppSettings $settings)
    {
    }

    public function sendText(string $to, string $message): array
    {
        return $this->postMessage([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $message,
            ],
        ]);
    }

    public function sendTemplate(
        string $to,
        string $templateName,
        string $languageCode = 'pt_BR',
        ?string $headerMediaType = null,
        ?string $headerMediaId = null,
    ): array {
        $template = [
            'name' => $templateName,
            'language' => ['code' => $languageCode],
        ];

        if ($headerMediaType && $headerMediaId) {
            $template['components'] = [[
                'type' => 'header',
                'parameters' => [[
                    'type' => $headerMediaType,
                    $headerMediaType => ['id' => $headerMediaId],
                ]],
            ]];
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
        if (! $this->settings->isReadyToSend()) {
            throw new RuntimeException('WhatsApp ainda não tem WHATSAPP_ACCESS_TOKEN e WHATSAPP_PHONE_NUMBER_ID configurados no servidor.');
        }

        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new RuntimeException('Arquivo de mídia não encontrado para upload no WhatsApp.');
        }

        $handle = fopen($absolutePath, 'r');
        if ($handle === false) {
            throw new RuntimeException('Não foi possível abrir a mídia para upload no WhatsApp.');
        }

        try {
            $response = Http::withToken(config('services.whatsapp.access_token'))
                ->acceptJson()
                ->attach('file', $handle, basename($absolutePath), ['Content-Type' => $mimeType])
                ->post($this->graphUrl('media'), [
                    'messaging_product' => 'whatsapp',
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

    public function sendMedia(string $to, string $mediaType, string $mediaId, ?string $caption = null): array
    {
        if (! in_array($mediaType, ['image', 'video'], true)) {
            throw new RuntimeException('Tipo de mídia inválido para WhatsApp.');
        }

        $media = ['id' => $mediaId];
        if (filled($caption)) {
            $media['caption'] = $caption;
        }

        return $this->postMessage([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => $mediaType,
            $mediaType => $media,
        ]);
    }

    private function postMessage(array $payload): array
    {
        if (! $this->settings->isReadyToSend()) {
            throw new RuntimeException('WhatsApp ainda não tem WHATSAPP_ACCESS_TOKEN e WHATSAPP_PHONE_NUMBER_ID configurados no servidor.');
        }

        $response = Http::withToken(config('services.whatsapp.access_token'))
            ->acceptJson()
            ->post($this->graphUrl('messages'), $payload);

        if ($response->failed()) {
            throw new RuntimeException('Falha ao enviar WhatsApp: '.$response->body());
        }

        return $response->json() ?? [];
    }

    private function graphUrl(string $edge): string
    {
        $baseUrl = rtrim(config('services.whatsapp.graph_url', 'https://graph.facebook.com/v20.0'), '/');
        $phoneNumberId = config('services.whatsapp.phone_number_id');

        return "{$baseUrl}/{$phoneNumberId}/{$edge}";
    }
}
