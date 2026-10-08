<?php

namespace App\Modules\WhatsApp\Services;

use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Fala com a Manuela de verdade: o subagente de atendimento da Naia, que roda
 * no Hermes (alphakora). O Hermes expõe um API server compatível com OpenAI
 * (`API_SERVER_ENABLED=true`, POST /v1/chat/completions, Bearer = API_SERVER_KEY).
 * Esse endpoint é sem estado, então cada chamada leva o histórico da conversa.
 *
 * Contrato da resposta: só o texto que vai pro cliente. Se precisar de uma
 * pessoa, a Manuela começa com [HUMANO] — a conversa é sinalizada na tela.
 */
class ManuelaAgentClient
{
    public const HANDOFF_TAG = '[HUMANO]';

    private const HISTORY_LIMIT = 20;

    public function isConfigured(): bool
    {
        return filled(config('services.whatsapp.manuela_url'));
    }

    /**
     * @return array{reply: string, needs_human: bool}
     */
    public function reply(WhatsAppConversation $conversation, string $systemPrompt): array
    {
        $response = Http::withToken((string) config('services.whatsapp.manuela_token'))
            ->acceptJson()
            ->timeout(max(10, (int) config('services.whatsapp.manuela_timeout', 60)))
            ->post($this->endpoint(), [
                'model' => config('services.whatsapp.manuela_model', 'hermes-agent'),
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ...$this->history($conversation),
                ],
                // Identifica o cliente pro Hermes separar memória/sessão por contato.
                'user' => 'whatsapp:'.$conversation->wa_id,
            ]);

        if ($response->failed()) {
            throw new RuntimeException('Manuela (Hermes) respondeu HTTP '.$response->status().': '.Str::limit($response->body(), 300));
        }

        $text = trim((string) $response->json('choices.0.message.content'));

        if ($text === '') {
            throw new RuntimeException('Manuela (Hermes) devolveu resposta vazia.');
        }

        $needsHuman = Str::contains($text, self::HANDOFF_TAG);
        $text = trim(str_replace(self::HANDOFF_TAG, '', $text));

        return ['reply' => $text, 'needs_human' => $needsHuman];
    }

    /** @return array<int, array{role: string, content: string}> */
    private function history(WhatsAppConversation $conversation): array
    {
        return $conversation->messages()
            ->whereIn('direction', ['inbound', 'outbound'])
            ->whereNotIn('status', ['failed', 'draft_no_token'])
            ->latest('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->reverse()
            ->map(fn (WhatsAppMessage $message) => [
                'role' => $message->direction === 'inbound' ? 'user' : 'assistant',
                'content' => $message->type === 'text' ? (string) $message->body : '['.$message->preview().']',
            ])
            ->values()
            ->all();
    }

    private function endpoint(): string
    {
        $base = rtrim((string) config('services.whatsapp.manuela_url'), '/');

        return Str::endsWith($base, '/chat/completions') ? $base : $base.'/chat/completions';
    }
}
