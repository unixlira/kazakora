<?php

namespace App\Modules\WhatsApp\Services;

use App\Modules\WhatsApp\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Http;

/**
 * Mídia recebida (foto, áudio, vídeo, documento) só existe na Meta: o ID vira
 * uma URL temporária que exige o token. Usado pela tela (proxy) e pela
 * transcrição de áudio da Manuela.
 */
class WhatsAppMediaDownloader
{
    /** @return array{body: string, mime_type: string}|null */
    public function download(WhatsAppMessage $message): ?array
    {
        $mediaId = $message->mediaId();
        $token = config('services.whatsapp.access_token');

        if (! $mediaId || ! filled($token)) {
            return null;
        }

        $baseUrl = rtrim(config('services.whatsapp.graph_url', 'https://graph.facebook.com/v20.0'), '/');
        $meta = Http::withToken($token)->acceptJson()->timeout(20)->get("{$baseUrl}/{$mediaId}");

        if ($meta->failed() || ! $meta->json('url')) {
            return null;
        }

        $file = Http::withToken($token)->timeout(60)->get($meta->json('url'));

        if ($file->failed()) {
            return null;
        }

        return [
            'body' => $file->body(),
            'mime_type' => $meta->json('mime_type') ?: $file->header('Content-Type') ?: 'application/octet-stream',
        ];
    }
}
