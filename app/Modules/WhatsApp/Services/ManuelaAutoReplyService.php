<?php

namespace App\Modules\WhatsApp\Services;

use App\Modules\Checkout\Jobs\SendOrderApprovedWhatsAppJob;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
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
     *
     * @param  (callable(string): void)|null  $sendNow  manda uma mensagem na hora (o "só um minutinho" da busca)
     */
    public function buildReply(WhatsAppConversation $conversation, string $message, ?callable $sendNow = null): array
    {
        if ($this->agent->isConfigured()) {
            try {
                $remote = $this->agent->reply($conversation, $this->systemPrompt($conversation), $sendNow);

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

    public function systemPrompt(?WhatsAppConversation $conversation = null): string
    {
        $s = $this->settings->all();
        $tag = ManuelaAgentClient::HANDOFF_TAG;
        $product = $conversation ? ManuelaAgentClient::productInContext($conversation) : null;
        $productBlock = $product
            ? "Produto em conversa com este cliente (ficha completa, é daqui que saem as respostas):\n".json_encode($product, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            : 'Nenhum produto aberto com este cliente ainda.';
        $pedidos = $conversation ? self::pedidosDaConversa($conversation) : [];
        $pedidosBlock = $pedidos !== []
            ? "Pedidos deste cliente (os avisos automáticos deles foram enviados nesta conversa; é daqui que saem status, nota e rastreio):\n".json_encode($pedidos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
            : 'Nenhum pedido ligado a esta conversa.';

        return <<<PROMPT
{$s['agent_instructions']}

Loja: {$s['brand_name']} ({$s['store_base_url']}). Seu nome: {$s['attendant_name']}. Tom: {$s['tone']}.
Horário de atendimento humano: {$s['business_hours']}.
Proibido: {$s['forbidden_promises']}.
Agora é {$this->agora()} (horário de Brasília).

Você trabalha com vendas: o assunto da conversa são os produtos da loja. Siga este fluxo, sempre com conversa natural, simpática e humana (nunca copie as frases abaixo ao pé da letra, fale do seu jeito):

1. O cliente falou de um produto (que viu no Instagram, num vídeo, ouviu falar, quer comprar, tem dúvida): chame buscar_produto com as palavras que descrevem o produto. Nunca diga que a loja não tem um produto sem ter buscado.
2. A busca achou: pergunte se é aquele produto, citando o nome (ex: "É a Webcam Full HD 1080p? Quer mais informações sobre ela?"). Se vierem 2 ou 3 parecidos, cite os nomes e pergunte qual é. Ainda não despeje a descrição.
3. O cliente confirmou: chame abrir_produto e pergunte qual é a dúvida dele. Se ele já tinha feito a pergunta antes, responda direto.
4. A dúvida: responda SÓ com o que está na ficha do produto (descrição, preço, estoque, marca, modelo). Se a resposta não estiver na ficha, não chute: mande o link do produto e peça pra ele dar uma olhada na página pra ver se encontra a informação por lá.
5. Depois do link: se ele achou ou ficou satisfeito, siga ajudando na compra (mande o link quando ele quiser comprar). Se não encontrou, continua com a dúvida, ficou insatisfeito ou pediu uma pessoa, comece a resposta com {$tag} e diga com carinho que uma pessoa do time vai continuar o atendimento.
6. Se a busca não achar no site, o sistema já mandou ao cliente a mensagem "{$this->searchingElsewhere()}" e procurou nos anúncios da loja na Shopee e no Mercado Livre: não repita esse aviso. Achou lá: siga o mesmo fluxo, com o link do anúncio. Não achou em nenhum lugar: diga que não encontrou esse produto e comece a resposta com {$tag} pra uma pessoa do time verificar.
7. Produto sem estoque (em_estoque falso): conte que no momento está sem estoque e ofereça pra uma pessoa do time avisar quando chegar, com {$tag}.

{$productBlock}

Mensagens automáticas da loja (modelos do WhatsApp): no histórico aparecem como "[mensagem automática da loja enviada ao cliente]" e o toque nos botões como "[cliente tocou no botão ...]". São a confirmação do pedido (botões Tudo Certo e Corrigir Informações), o aviso de pedido enviado (Ir para o rastreio e Falar com Atendente) e o de entregue (Não Recebi). Quem trata o toque no botão é o sistema; você responde quando o cliente escreve, manda áudio ou quando o sistema te passa o toque. Nunca deixe o cliente sem resposta e nunca trate esse aviso como se o cliente tivesse escrito:
- Confirmou os dados por escrito ("tá certo", "ok", "isso mesmo") ou tocou em Tudo Certo: agradeça e diga que o pedido segue para separação.
- Tocou em Corrigir Informações ou disse que algo está errado, sem dizer o quê: pergunte o que precisa corrigir (nome, e-mail, endereço, número, complemento, CEP).
- Mandou o dado certo: repita o dado para ele conferir, diga que o time vai ajustar no pedido e comece a resposta com {$tag}. Nunca diga que já alterou. Se o pedido já tem nota_emitida ou rastreio, diga com sinceridade que a nota e a etiqueta já saíram e que o time vai ver o que ainda dá para ajustar.
- Falar com atendente, não recebeu, entrega com problema, troca ou cancelamento: comece com {$tag} e diga que uma pessoa do time vai continuar.
- Perguntou do pedido (status, nota, rastreio, prazo): responda só pelos "Pedidos deste cliente". Rastreio só com o link_rastreio de lá. Prazo que não estiver lá, não invente.
- Nunca fale de pedido que não está nessa lista nem passe dados de outra pessoa.

{$pedidosBlock}

Regras de formato:
- Responda SOMENTE com o texto que vai para o cliente no WhatsApp: curto, em português do Brasil, sem markdown, sem travessões.
- Preço, estoque, link e características só da ficha ou da busca. Nunca invente.
- FOTO do cliente: responda sobre o produto DA FOTO, nunca sobre o produto falado antes. Se a foto não foi identificada, pergunte qual é o produto antes de falar de preço ou estoque.
- Preço e estoque só depois de abrir a ficha DESTE produto (abrir_produto ou ficha em conversa). Na dúvida sobre qual produto é, pergunte: errar o preço afasta o cliente.
- Se o assunto envolver {$s['handoff_keywords']}, comece a resposta com {$tag} e diga ao cliente que uma pessoa do time vai acompanhar.
PROMPT;
    }

    /**
     * Pedidos cujos avisos (modelos da Meta) saíram para esta conversa, os
     * mais novos primeiro. Achado real 19/10, #3274: o cliente tocou em
     * "Corrigir Informações" e a Manuela não sabia de pedido nenhum.
     *
     * @return list<array<string, mixed>>
     */
    public static function pedidosDaConversa(WhatsAppConversation $conversation): array
    {
        $ids = $conversation->messages()
            ->where('direction', 'outbound')
            ->where('created_at', '>=', now()->subDays(60))
            ->latest('id')
            ->limit(50)
            ->pluck('payload')
            ->map(fn ($payload) => $payload['order_id'] ?? null)
            ->filter()
            ->unique()
            ->take(3)
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $status = [
            Order::STATUS_PENDING => 'aguardando pagamento',
            Order::STATUS_AWAITING_PAYMENT => 'aguardando pagamento',
            Order::STATUS_PAID => 'pago, em separação',
            Order::STATUS_SHIPPED => 'enviado',
            Order::STATUS_COMPLETED => 'entregue',
            Order::STATUS_CANCELLED => 'cancelado',
        ];

        return Order::query()
            ->with(['items', 'user', 'invoice', 'channelShipment', 'latestCorreiosPrePostagem'])
            ->whereIn('id', $ids)
            ->get()
            ->sortByDesc('id')
            ->map(fn (Order $order) => array_filter([
                'numero' => $order->numero,
                'feito_em' => $order->created_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
                'status' => $status[$order->status] ?? $order->status,
                'itens' => $order->items->map(fn ($item) => "{$item->quantity}x {$item->product_name}")->all(),
                'total' => 'R$ '.number_format((float) $order->total, 2, ',', '.'),
                'nome' => $order->shipping_name ?: $order->user?->name,
                'email' => $order->shipping_email ?: $order->user?->email,
                'endereco' => SendOrderApprovedWhatsAppJob::endereco($order),
                'confirmacao_do_cliente' => match ($order->whatsapp_confirmacao) {
                    'ok' => 'tocou em Tudo Certo',
                    'corrigir' => 'pediu para corrigir os dados',
                    default => 'ainda não respondeu',
                },
                'nota_emitida' => $order->invoice?->status === Invoice::STATUS_AUTHORIZED,
                'rastreio' => $order->trackingCode(),
                'link_rastreio' => route('rastreio.ver', $order->trackingRef()),
            ], fn ($valor) => $valor !== null && $valor !== ''))
            ->values()
            ->all();
    }

    private function searchingElsewhere(): string
    {
        return ManuelaAgentClient::SEARCHING_ELSEWHERE_REPLY;
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
