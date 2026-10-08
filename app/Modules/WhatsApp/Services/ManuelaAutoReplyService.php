<?php

namespace App\Modules\WhatsApp\Services;

use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Support\WhatsAppSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ManuelaAutoReplyService
{
    public const HANDOFF_REPLY = 'Vou chamar uma pessoa do time pra continuar com você. Já já te respondemos por aqui.';

    public function __construct(
        private readonly WhatsAppSettings $settings,
        private readonly ManuelaAgentClient $agent,
        private readonly ManuelaCatalog $catalog,
    ) {
    }

    private function agora(): string
    {
        return now()->locale('pt_BR')->translatedFormat('l, d/m/Y H:i');
    }

    /**
     * Com MANUELA_AGENT_URL configurada, quem responde é a Manuela da Naia
     * (Hermes). Se o Hermes cair ou demorar, cai nas regras locais abaixo pra
     * cliente nunca ficar sem resposta.
     */
    public function buildReply(WhatsAppConversation $conversation, string $message): array
    {
        if ($this->agent->isConfigured()) {
            try {
                $remote = $this->agent->reply($conversation, $this->systemPrompt());

                return [
                    'intent' => 'manuela_'.$remote['provider'],
                    'confidence' => 1.0,
                    'reply' => $remote['reply'],
                    'needs_human' => $remote['needs_human'] || $this->needsHuman(Str::lower(Str::ascii($message)), $this->settings->all()),
                    'needs_data' => [],
                    'suggested_next_action' => $remote['needs_human'] ? 'handoff' : 'reply',
                    'sales_stage' => 'atendimento',
                    'source' => $remote['provider'],
                ];
            } catch (Throwable $exception) {
                Log::warning('manuela_remote_failed', ['conversation_id' => $conversation->id, 'error' => $exception->getMessage()]);
            }
        }

        $reply = $this->ruleBasedReply($message);

        // Roteiro fixo não conversa: achado real 2026-10-08, cliente
        // perguntou de amostra e de câmera e recebeu a mesma saudação duas
        // vezes. Se a resposta seria repetida, ou se ele não entendeu de novo,
        // chama uma pessoa em vez de insistir.
        $lastManuela = $conversation->messages()->where('direction', 'outbound')->where('sent_by', 'manuela')->latest('id')->value('body');

        if (! $reply['needs_human'] && $lastManuela !== null && ($reply['reply'] === $lastManuela || $reply['intent'] === 'outro')) {
            $reply = [
                'intent' => $reply['intent'],
                'confidence' => 0.5,
                // Já avisou que vai chamar alguém: não manda o aviso de novo.
                'reply' => $lastManuela === self::HANDOFF_REPLY ? '' : self::HANDOFF_REPLY,
                'needs_human' => true,
                'needs_data' => [],
                'suggested_next_action' => 'handoff',
                'sales_stage' => $reply['sales_stage'],
            ];
        }

        return $reply + ['source' => 'regras'];
    }

    public function systemPrompt(): string
    {
        $s = $this->settings->all();
        $tag = ManuelaAgentClient::HANDOFF_TAG;

        return <<<PROMPT
{$s['agent_instructions']}

Loja: {$s['brand_name']} ({$s['store_base_url']}). Seu nome: {$s['attendant_name']}. Tom: {$s['tone']}.
Horário de atendimento humano: {$s['business_hours']}.
Categorias prioritárias: {$s['priority_categories']}.
Proibido: {$s['forbidden_promises']}.
Faça no máximo {$s['max_questions_before_close']} perguntas antes de sugerir o próximo passo de compra.
Agora é {$this->agora()} (horário de Brasília).

Produtos à venda no site (nome, preço, link). Só cite produto, preço e link desta lista; se o cliente pedir algo que não está aqui, diga que vai confirmar com o time:
{$this->catalog->asText()}

Regras de formato:
- Responda SOMENTE com o texto que vai para o cliente no WhatsApp: curto, em português do Brasil, sem markdown, sem travessões.
- Se o assunto envolver {$s['handoff_keywords']}, ou se você não tiver certeza da informação, comece a resposta com {$tag} e diga ao cliente que uma pessoa do time vai acompanhar.
PROMPT;
    }

    private function ruleBasedReply(string $message): array
    {
        $settings = $this->settings->all();
        $normalized = Str::lower(Str::ascii($message));
        $intent = $this->intent($normalized);
        $needsHuman = $this->needsHuman($normalized, $settings);

        if ($needsHuman) {
            return [
                'intent' => $intent,
                'confidence' => 0.9,
                'reply' => 'Vou te ajudar com isso. Me manda o número do pedido ou mais detalhes, por favor. Como pode precisar de conferência, eu já deixo sinalizado para uma pessoa do time acompanhar também.',
                'needs_human' => true,
                'needs_data' => [],
                'suggested_next_action' => 'handoff',
                'sales_stage' => 'suporte',
            ];
        }

        $reply = match ($intent) {
            'frete_prazo' => 'Consigo te ajudar com o prazo. Me manda seu CEP, por favor, que eu confiro o caminho mais seguro pra entrega.',
            'preco_desconto' => 'Consigo te orientar pelo melhor caminho de compra. Você pensa em pegar uma unidade ou mais de uma?',
            'pedido_status' => 'Me manda o número do pedido, por favor. Com ele eu consigo localizar e te responder com mais segurança.',
            'troca_garantia' => 'Vou te orientar com cuidado. Me manda o número do pedido e uma foto ou vídeo curto mostrando o problema, por favor.',
            'lead_compra' => $settings['closing_template'],
            'produto_duvida' => 'Me fala qual modelo ou produto você está olhando. Se tiver o link ou uma foto, melhor ainda, que eu te digo o caminho certo sem chutar informação.',
            default => $settings['welcome_message'],
        };

        return [
            'intent' => $intent,
            'confidence' => $intent === 'outro' ? 0.55 : 0.78,
            'reply' => $reply,
            'needs_human' => false,
            'needs_data' => $this->needsData($intent),
            'suggested_next_action' => $intent === 'lead_compra' ? 'send_product_link' : 'ask_one_question',
            'sales_stage' => in_array($intent, ['lead_compra', 'preco_desconto'], true) ? 'consideracao' : 'atendimento',
        ];
    }

    private function intent(string $text): string
    {
        return match (true) {
            Str::contains($text, ['frete', 'prazo', 'entrega', 'cep', 'chega quando']) => 'frete_prazo',
            Str::contains($text, ['desconto', 'cupom', 'preco', 'quanto fica', 'valor']) => 'preco_desconto',
            Str::contains($text, ['pedido', 'rastreamento', 'codigo', 'status']) => 'pedido_status',
            Str::contains($text, ['troca', 'garantia', 'defeito', 'devolucao', 'quebrou']) => 'troca_garantia',
            Str::contains($text, ['comprar', 'quero', 'tem esse', 'manda o link', 'finalizar']) => 'lead_compra',
            Str::contains($text, ['serve', 'funciona', 'medida', 'voltagem', '110', '220', 'compativel', 'material']) => 'produto_duvida',
            default => 'outro',
        };
    }

    private function needsHuman(string $text, array $settings): bool
    {
        $keywords = collect(explode(',', $settings['handoff_keywords']))
            ->map(fn (string $keyword) => trim(Str::lower(Str::ascii($keyword))))
            ->filter()
            ->all();

        return Str::contains($text, $keywords)
            || Str::contains($text, ['procon', 'processo', 'advogado', 'reclame aqui']);
    }

    private function needsData(string $intent): array
    {
        return match ($intent) {
            'frete_prazo' => ['cep'],
            'pedido_status', 'troca_garantia' => ['numero_pedido'],
            'produto_duvida', 'lead_compra' => ['produto_ou_link'],
            default => [],
        };
    }
}
