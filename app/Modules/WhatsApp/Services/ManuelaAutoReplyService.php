<?php

namespace App\Modules\WhatsApp\Services;

use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Support\WhatsAppSettings;
use Illuminate\Support\Str;

class ManuelaAutoReplyService
{
    public function __construct(private readonly WhatsAppSettings $settings)
    {
    }

    public function buildReply(WhatsAppConversation $conversation, string $message): array
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
