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
     * Achado real 2026-10-08: áudio do cliente ficou sem transcrição porque o
     * Google respondeu 503 ("high demand"). Cada modelo tenta 2 vezes quando
     * está sobrecarregado e depois passa pro próximo da lista (o pedido, o de
     * conversa, o reserva).
     *
     * @param  array<int, array{role: string, parts: array<int, array<string, mixed>>}>  $contents
     */
    public function generate(string $model, array $contents, ?string $systemInstruction = null, int $maxOutputTokens = 2048): string
    {
        $models = array_values(array_unique(array_filter([
            $model,
            (string) config('services.gemini.chat_model'),
            ...(array) config('services.gemini.fallback_models', []),
        ])));
        $last = null;

        foreach ($models as $candidate) {
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                try {
                    return $this->generateOnce($candidate, $contents, $systemInstruction, $maxOutputTokens);
                } catch (GeminiUnavailableException|\Illuminate\Http\Client\ConnectionException $exception) {
                    $last = $exception;

                    if ($attempt === 1) {
                        usleep(max(0, (int) config('services.gemini.retry_delay_ms', 1500)) * 1000);
                    }
                } catch (RuntimeException $exception) {
                    // Não é sobrecarga (modelo não serve pra isso, resposta
                    // vazia): não insiste nele, vai pro próximo.
                    $last = $exception;

                    break;
                }
            }
        }

        throw $last ?? new RuntimeException('Gemini sem modelo configurado.');
    }

    public function transcribe(string $audio, string $mimeType): string
    {
        // Se o modelo de áudio falhar, generate() passa pro de conversa, que
        // também ouve áudio.
        return $this->generate((string) config('services.gemini.audio_model'), [[
            'role' => 'user',
            'parts' => [
                ['inline_data' => ['mime_type' => Str::before($mimeType, ';') ?: 'audio/ogg', 'data' => base64_encode($audio)]],
                ['text' => 'Transcreva este áudio de WhatsApp em português do Brasil, exatamente como foi falado. Responda só com a transcrição, sem comentários.'],
            ],
        ]], null, 1024);
    }

    /**
     * @param  array<int, array{role: string, parts: array<int, array<string, mixed>>}>  $contents
     */
    private function generateOnce(string $model, array $contents, ?string $systemInstruction, int $maxOutputTokens): string
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

        if (in_array($response->status(), [429, 500, 502, 503, 504], true)) {
            throw new GeminiUnavailableException("Gemini ({$model}) respondeu HTTP {$response->status()}: ".Str::limit($response->body(), 200));
        }

        if ($response->failed()) {
            throw new RuntimeException("Gemini ({$model}) respondeu HTTP {$response->status()}: ".Str::limit($response->body(), 300));
        }

        // Partes de raciocínio (thought) não vão pro cliente.
        $text = collect($response->json('candidates.0.content.parts') ?? [])
            ->reject(fn ($part) => ($part['thought'] ?? false) === true)
            ->pluck('text')
            ->filter()
            ->implode('');

        if (trim($text) === '') {
            throw new RuntimeException("Gemini ({$model}) devolveu resposta vazia (".($response->json('candidates.0.finishReason') ?? 'sem motivo').').');
        }

        return trim($text);
    }
}
