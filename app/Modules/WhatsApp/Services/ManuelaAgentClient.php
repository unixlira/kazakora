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

    /** Mensagem enquanto procura na Shopee/ML (pedido 2026-10-08, texto do Lira). */
    public const SEARCHING_ELSEWHERE_REPLY = 'Só mais um minutinho que estou verificando em outro catálogo 😊';

    /** Produto que a Manuela abriu com o cliente: fica na conversa pras próximas dúvidas. */
    public const PRODUCT_CONTEXT_KEY = 'manuela_produto';

    private const HISTORY_LIMIT = 20;

    /** Voltas de ferramenta por resposta (buscar, abrir): evita laço infinito. */
    private const MAX_TOOL_ROUNDS = 4;

    public function __construct(private readonly GeminiClient $gemini, private readonly ManuelaProductSearch $products) {}

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
     * @param  (callable(string): void)|null  $sendNow  manda uma mensagem pro cliente na hora (o "só um minutinho")
     * @return array{reply: string, needs_human: bool, provider: string}
     */
    public function reply(WhatsAppConversation $conversation, string $systemPrompt, ?callable $sendNow = null): array
    {
        $provider = $this->provider() ?? throw new RuntimeException('Manuela sem cérebro configurado (nem Hermes nem Gemini).');

        $text = $provider === 'hermes'
            ? $this->replyFromHermes($conversation, $systemPrompt)
            : $this->replyFromGemini($conversation, $systemPrompt, $sendNow);

        $needsHuman = Str::contains($text, self::HANDOFF_TAG);
        $text = trim(str_replace(self::HANDOFF_TAG, '', $text));

        if ($text === '') {
            throw new RuntimeException("Manuela ({$provider}) devolveu só a marcação, sem texto pro cliente.");
        }

        return ['reply' => $text, 'needs_human' => $needsHuman, 'provider' => $provider];
    }

    private function replyFromGemini(WhatsAppConversation $conversation, string $systemPrompt, ?callable $sendNow): string
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

        // O "só um minutinho" sai uma vez só, mesmo que ela busque de novo.
        $sendOnce = $sendNow ? function (string $text) use (&$sendOnce, $sendNow) {
            $sendNow($text);
            $sendOnce = null;
        } : null;

        // A Manuela pede a busca (buscar_produto/abrir_produto), o sistema
        // procura de verdade e devolve; ela só fala de produto com base nisso.
        for ($round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++) {
            $parts = $this->gemini->request((string) config('services.gemini.chat_model'), $contents, $systemPrompt, $round < self::MAX_TOOL_ROUNDS ? self::tools() : []);
            $calls = collect($parts)->pluck('functionCall')->filter()->values();

            if ($calls->isEmpty()) {
                $text = GeminiClient::text($parts);

                if ($text === '') {
                    throw new RuntimeException('Gemini devolveu resposta vazia.');
                }

                return $text;
            }

            $contents[] = ['role' => 'model', 'parts' => $parts];
            $contents[] = ['role' => 'user', 'parts' => $calls->map(fn (array $call) => ['functionResponse' => array_filter([
                'id' => $call['id'] ?? null,
                'name' => $call['name'],
                'response' => $this->runTool($conversation, $call['name'], (array) ($call['args'] ?? []), $sendOnce),
            ], fn ($value) => $value !== null)])->all()];
        }

        throw new RuntimeException('Manuela não fechou a resposta depois das buscas.');
    }

    /** @return array<int, array<string, mixed>> */
    public static function tools(): array
    {
        return [
            [
                'name' => 'buscar_produto',
                'description' => 'Procura um produto da loja pelo nome. Use sempre que o cliente falar de um produto que viu, ouviu, quer ou perguntou. Procura primeiro no site da KazaKora e, se não achar, nos anúncios da loja na Shopee e no Mercado Livre.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'termo' => ['type' => 'string', 'description' => 'Só as palavras que descrevem o produto, ex: "webcam", "caixa de ferramentas 168 peças". Sem "vi no Instagram", sem saudação.'],
                    ],
                    'required' => ['termo'],
                ],
            ],
            [
                'name' => 'abrir_produto',
                'description' => 'Abre a ficha completa (descrição, preço, estoque, link) de um produto achado pelo buscar_produto, depois que o cliente confirmou que é ele.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'origem' => ['type' => 'string', 'enum' => [ManuelaProductSearch::ORIGIN_STORE, ManuelaProductSearch::ORIGIN_SHOPEE, ManuelaProductSearch::ORIGIN_MERCADO_LIVRE]],
                        'id' => ['type' => 'string'],
                    ],
                    'required' => ['origem', 'id'],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function runTool(WhatsAppConversation $conversation, string $name, array $args, ?callable $sendNow): array
    {
        if ($name === 'abrir_produto') {
            $product = $this->products->details((string) ($args['origem'] ?? ''), (string) ($args['id'] ?? ''));

            if (! $product) {
                return ['erro' => 'Produto não encontrado. Busque de novo com buscar_produto.'];
            }

            $conversation->update(['metadata' => array_merge($conversation->metadata ?? [], [
                self::PRODUCT_CONTEXT_KEY => $product + ['aberto_em' => now()->toIso8601String()],
            ])]);

            return ['produto' => $product];
        }

        if ($name !== 'buscar_produto') {
            return ['erro' => "Ferramenta {$name} não existe."];
        }

        $term = trim((string) ($args['termo'] ?? ''));
        $found = $this->products->searchStore($term);

        if ($found !== []) {
            return ['onde' => 'site da KazaKora', 'encontrados' => $found];
        }

        // Não está no site: avisa o cliente e procura na Shopee e no ML.
        if ($sendNow) {
            $sendNow(self::SEARCHING_ELSEWHERE_REPLY);
        }

        $found = $this->products->searchMarketplaces($term);

        return $found !== []
            ? ['onde' => 'anúncios da KazaKora na Shopee/Mercado Livre', 'aviso_ja_enviado_ao_cliente' => self::SEARCHING_ELSEWHERE_REPLY, 'encontrados' => $found]
            : ['onde' => 'nenhum catálogo', 'aviso_ja_enviado_ao_cliente' => self::SEARCHING_ELSEWHERE_REPLY, 'encontrados' => []];
    }

    /**
     * Produto aberto na conversa nas últimas 24h, pro prompt: as dúvidas
     * seguintes são respondidas com a ficha dele.
     *
     * @return array<string, mixed>|null
     */
    public static function productInContext(WhatsAppConversation $conversation): ?array
    {
        $product = $conversation->metadata[self::PRODUCT_CONTEXT_KEY] ?? null;

        if (! is_array($product) || ! isset($product['aberto_em']) || now()->subDay()->gt($product['aberto_em'])) {
            return null;
        }

        return collect($product)->except('aberto_em')->all();
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
