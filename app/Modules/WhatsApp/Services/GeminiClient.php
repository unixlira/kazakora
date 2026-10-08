<?php

namespace App\Modules\WhatsApp\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Gemini (conta do dono, GEMINI_API_KEY): cérebro da Manuela no WhatsApp e
 * transcrição dos áudios dos clientes (pedido 2026-10-08: "deixar ela
 * respondendo tudo"). Chamada direta à API REST, sem SDK.
 */
class GeminiClient
{
    private const BASE_URL = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public function isConfigured(): bool
    {
        return filled(config('services.gemini.api_key'));
    }

    /**
     * @param  array<int, array{role: string, parts: array<int, array<string, mixed>>}>  $contents
     */
    public function generate(string $model, array $contents, ?string $systemInstruction = null, int $maxOutputTokens = 2048): string
    {
        $body = [
            'contents' => $contents,
            'generationConfig' => ['maxOutputTokens' => $maxOutputTokens],
        ];

        if ($systemInstruction !== null) {
            $body['systemInstruction'] = ['parts' => [['text' => $systemInstruction]]];
        }

        $response = Http::withHeaders(['x-goog-api-key' => (string) config('services.gemini.api_key')])
            ->acceptJson()
            ->timeout(max(10, (int) config('services.gemini.timeout', 45)))
            ->post(self::BASE_URL.$model.':generateContent', $body);

        if ($response->failed()) {
            throw new RuntimeException('Gemini respondeu HTTP '.$response->status().': '.Str::limit($response->body(), 300));
        }

        // Partes de raciocínio (thought) não vão pro cliente.
        $text = collect($response->json('candidates.0.content.parts') ?? [])
            ->reject(fn ($part) => ($part['thought'] ?? false) === true)
            ->pluck('text')
            ->filter()
            ->implode('');

        if (trim($text) === '') {
            throw new RuntimeException('Gemini devolveu resposta vazia ('.($response->json('candidates.0.finishReason') ?? 'sem motivo').').');
        }

        return trim($text);
    }

    public function transcribe(string $audio, string $mimeType): string
    {
        $contents = [[
            'role' => 'user',
            'parts' => [
                ['inline_data' => ['mime_type' => Str::before($mimeType, ';') ?: 'audio/ogg', 'data' => base64_encode($audio)]],
                ['text' => 'Transcreva este áudio de WhatsApp em português do Brasil, exatamente como foi falado. Responda só com a transcrição, sem comentários.'],
            ],
        ]];

        try {
            return $this->generate((string) config('services.gemini.audio_model'), $contents, null, 1024);
        } catch (RuntimeException $exception) {
            // Modelo de áudio fora do ar ou recusando: o de conversa também ouve áudio.
            if (config('services.gemini.audio_model') === config('services.gemini.chat_model')) {
                throw $exception;
            }

            return $this->generate((string) config('services.gemini.chat_model'), $contents, null, 1024);
        }
    }
}
