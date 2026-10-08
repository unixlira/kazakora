<?php

namespace App\Modules\WhatsApp\Services;

use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * O cérebro da Manuela. Dois caminhos, nesta ordem:
 *
 * 1. Hermes (MANUELA_AGENT_URL): API server compatível com OpenAI
 *    (POST /v1/chat/completions, Bearer = API_SERVER_KEY).
 * 2. Gemini da conta do dono (GEMINI_API_KEY) — o que está valendo desde
 *    2026-10-08 ("vamos deixar ela respondendo tudo").
 *
 * Os dois são sem estado: cada chamada leva o histórico da conversa.
 *
 * Contrato da resposta: só o texto que vai pro cliente. Se precisar de uma
 * pessoa, a Manuela começa com [HUMANO] — a conversa é sinalizada na tela.
 */
class ManuelaAgentClient
{
    public const HANDOFF_TAG = '[HUMANO]';

    private const HISTORY_LIMIT = 20;

    public function __construct(private readonly GeminiClient $gemini) {}

    public function isConfigured(): bool
    {
        return $this->provider() !== null;
    }

    /** 'hermes', 'gemini' ou null (cai nas regras locais). */
    public function provider(): ?string
    {
        return match (true) {
            filled(config('services.whatsapp.manuela_url')) => 'hermes',
            $this->gemini->isConfigured() => 'gemini',
            default => null,
        };
    }

    /**
     * @return array{reply: string, needs_human: bool, provider: string}
     */
    public function reply(WhatsAppConversation $conversation, string $systemPrompt): array
    {
        $provider = $this->provider() ?? throw new RuntimeException('Manuela sem cérebro configurado (nem Hermes nem Gemini).');

        $text = $provider === 'hermes'
            ? $this->replyFromHermes($conversation, $systemPrompt)
            : $this->replyFromGemini($conversation, $systemPrompt);

        $needsHuman = Str::contains($text, self::HANDOFF_TAG);
        $text = trim(str_replace(self::HANDOFF_TAG, '', $text));

        if ($text === '') {
            throw new RuntimeException("Manuela ({$provider}) devolveu só a marcação, sem texto pro cliente.");
        }

        return ['reply' => $text, 'needs_human' => $needsHuman, 'provider' => $provider];
    }

    private function replyFromGemini(WhatsAppConversation $conversation, string $systemPrompt): string
    {
        // O Gemini quer user/model alternados e começando por user.
        $contents = [];

        foreach ($this->history($conversation) as $message) {
            $role = $message['role'] === 'user' ? 'user' : 'model';

            if ($contents === [] && $role === 'model') {
                continue;
            }

            if ($contents !== [] && end($contents)['role'] === $role) {
                $contents[array_key_last($contents)]['parts'][0]['text'] .= "\n".$message['content'];

                continue;
            }

            $contents[] = ['role' => $role, 'parts' => [['text' => $message['content']]]];
        }

        // E não aceita terminar com a fala dela (achado ao vivo: HTTP 400
        // "Requests ending with a model turn are not supported").
        while ($contents !== [] && end($contents)['role'] === 'model') {
            array_pop($contents);
        }

        if ($contents === []) {
            throw new RuntimeException('Conversa sem mensagem do cliente pra responder.');
        }

        return $this->gemini->generate((string) config('services.gemini.chat_model'), $contents, $systemPrompt);
    }

    private function replyFromHermes(WhatsAppConversation $conversation, string $systemPrompt): string
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

        return $text;
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
                'content' => $this->content($message),
            ])
            ->values()
            ->all();
    }

    /** Mídia vira descrição entre colchetes; áudio transcrito vai como texto. */
    private function content(WhatsAppMessage $message): string
    {
        if ($message->type === 'text') {
            return (string) $message->body;
        }

        if ($transcription = $message->payload['transcription'] ?? null) {
            return "[áudio do cliente, transcrito] {$transcription}";
        }

        return '[cliente enviou '.$message->preview().']';
    }

    private function endpoint(): string
    {
        $base = rtrim((string) config('services.whatsapp.manuela_url'), '/');

        return Str::endsWith($base, '/chat/completions') ? $base : $base.'/chat/completions';
    }
}
