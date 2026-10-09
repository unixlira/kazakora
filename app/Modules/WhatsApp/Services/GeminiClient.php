<?php

namespace App\Modules\WhatsApp\Services;

use App\Modules\WhatsApp\Models\GeminiUsageLog;
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
    public function generate(string $model, array $contents, ?string $systemInstruction = null, int $maxOutputTokens = 2048, string $purpose = GeminiUsageLog::PURPOSE_REPLY): string
    {
        $text = self::text($this->request($model, $contents, $systemInstruction, [], $maxOutputTokens, $purpose));

        if ($text === '') {
            throw new RuntimeException("Gemini ({$model}) devolveu resposta vazia.");
        }

        return $text;
    }

    /**
     * Uma volta da conversa com ferramentas (function calling). Devolve as
     * partes da resposta como vieram, sem as de raciocínio: texto e/ou
     * functionCall. Quem chama devolve essas partes intactas no próximo
     * pedido (o Gemini 3 exige a thoughtSignature de volta).
     *
     * @param  array<int, array{role: string, parts: array<int, array<string, mixed>>}>  $contents
     * @param  array<int, array<string, mixed>>  $functionDeclarations
     * @return array<int, array<string, mixed>>
     */
    public function request(string $model, array $contents, ?string $systemInstruction, array $functionDeclarations = [], int $maxOutputTokens = 2048, string $purpose = GeminiUsageLog::PURPOSE_REPLY): array
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
                    return $this->generateOnce($candidate, $contents, $systemInstruction, $functionDeclarations, $maxOutputTokens, $purpose);
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

    /** @param  array<int, array<string, mixed>>  $parts */
    public static function text(array $parts): string
    {
        return trim(collect($parts)->pluck('text')->filter()->implode(''));
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
        ]], null, 1024, GeminiUsageLog::PURPOSE_TRANSCRIPTION);
    }

    /**
     * @param  array<int, array{role: string, parts: array<int, array<string, mixed>>}>  $contents
     * @param  array<int, array<string, mixed>>  $functionDeclarations
     * @return array<int, array<string, mixed>>
     */
    private function generateOnce(string $model, array $contents, ?string $systemInstruction, array $functionDeclarations, int $maxOutputTokens, string $purpose): array
    {
        $body = [
            'contents' => $contents,
            'generationConfig' => ['maxOutputTokens' => $maxOutputTokens],
        ];

        if ($systemInstruction !== null) {
            $body['systemInstruction'] = ['parts' => [['text' => $systemInstruction]]];
        }

        if ($functionDeclarations !== []) {
            $body['tools'] = [['functionDeclarations' => $functionDeclarations]];
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

        $this->recordUsage($model, $purpose, $response->json('usageMetadata') ?? []);

        // Partes de raciocínio (thought) não vão pro cliente.
        $parts = collect($response->json('candidates.0.content.parts') ?? [])
            ->reject(fn ($part) => ($part['thought'] ?? false) === true)
            ->values()
            ->all();

        if (self::text($parts) === '' && ! collect($parts)->contains(fn ($part) => isset($part['functionCall']))) {
            throw new RuntimeException("Gemini ({$model}) devolveu resposta vazia (".($response->json('candidates.0.finishReason') ?? 'sem motivo').').');
        }

        return $parts;
    }

    /**
     * Tokens que o Google cobra nesta chamada. O raciocínio (thoughts) é
     * cobrado como saída. Nunca derruba a resposta ao cliente.
     *
     * @param  array<string, mixed>  $usage
     */
    private function recordUsage(string $model, string $purpose, array $usage): void
    {
        rescue(fn () => GeminiUsageLog::query()->create([
            'model' => $model,
            'purpose' => $purpose,
            'prompt_tokens' => (int) ($usage['promptTokenCount'] ?? 0),
            'output_tokens' => (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0),
            'total_tokens' => (int) ($usage['totalTokenCount'] ?? 0),
        ]), report: false);
    }
}
