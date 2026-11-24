<?php

namespace App\Modules\Cart\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\LeadCheckout;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use App\Modules\FerraKora\Services\CatalogoFerraKora;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use App\Modules\WhatsApp\Services\WhatsAppCloudApiClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mensagem de recuperação de carrinho pelo painel (pedido do Lira 2026-11-23):
 * botão por carrinho e "Disparar para todos" em Admin > Carrinhos abandonados.
 * Usa os modelos aprovados na Meta (KazaKora: recuperacao_carrinho_kazakora,
 * com foto do produto; FerraKora: carrinho_lembrete_ferrakora). Cada carrinho
 * recebe uma vez só (whatsapp_enviado_em).
 */
class RecuperacaoCarrinhoWhatsApp
{
    public const OK = 'enviado';

    public function __construct(private readonly WhatsAppCloudApiClient $whatsapp) {}

    /** Motivo para não enviar, ou null quando pode. */
    public function bloqueio(LeadCheckout $lead): ?string
    {
        return match (true) {
            $lead->status !== LeadCheckout::STATUS_ABERTO => 'O carrinho já virou pedido.',
            $lead->whatsapp_enviado_em !== null => 'A mensagem já foi enviada para este carrinho.',
            self::telefone($lead->telefone) === null => 'O carrinho não tem celular válido.',
            ! in_array($lead->site, ['kazakora', 'ferrakora'], true) => 'Loja sem modelo de mensagem aprovado.',
            empty($lead->itens) => 'O carrinho não tem produto.',
            default => null,
        };
    }

    /** Envia e devolve self::OK ou o motivo da falha. */
    public function enviar(LeadCheckout $lead): string
    {
        if ($motivo = $this->bloqueio($lead)) {
            return $motivo;
        }

        $telefone = self::telefone($lead->telefone);
        $item = $lead->itens[0];
        $produto = Product::query()->withTrashed()->find((int) ($item['product_id'] ?? 0));
        $pai = $produto?->parent_product_id ? Product::query()->withTrashed()->find($produto->parent_product_id) : $produto;
        // Variável de template da Meta não aceita quebra de linha nem tab.
        $nomeProduto = (string) preg_replace('/\s+/u', ' ', (string) ($item['nome'] ?? $pai?->name ?? 'produto'));
        $nome = self::primeiroNome($lead->nome);

        try {
            if ($lead->site === 'kazakora') {
                $modelo = 'recuperacao_carrinho_kazakora';
                $imagem = $pai?->images()->orderByDesc('is_primary')->orderBy('position')->first();
                $resposta = $this->whatsapp->paraLoja('kazakora')->sendTemplate($telefone, $modelo, 'pt_BR',
                    headerMediaType: 'image',
                    bodyParams: [$nome, $nomeProduto],
                    urlButtonParams: [0 => (string) ($pai?->slug ?? '')],
                    headerMediaLink: $imagem ? 'https://kazakora.com/storage/'.ltrim((string) $imagem->path, '/') : null);
            } else {
                $modelo = 'carrinho_lembrete_ferrakora';
                $registro = $pai ? FerraKoraProduto::query()->where('product_id', $pai->id)->first() : null;
                $slug = $registro ? app(CatalogoFerraKora::class)->slugPublicado($registro) : null;
                $resposta = $this->whatsapp->paraLoja('ferrakora')->sendTemplate($telefone, $modelo, 'pt_BR',
                    bodyParams: [$nome, $nomeProduto],
                    urlButtonParams: [0 => $slug ? 'produto/'.$slug : '?carrinho=1']);
            }
        } catch (Throwable $e) {
            Log::warning('Recuperação de carrinho por WhatsApp falhou', ['lead' => $lead->id, 'erro' => $e->getMessage()]);

            return 'O WhatsApp recusou: '.mb_substr($e->getMessage(), 0, 160);
        }

        $lead->forceFill(['whatsapp_enviado_em' => now()])->save();

        // Aparece no histórico da conversa no painel do WhatsApp (falha aqui não desfaz o envio).
        try {
            $conversa = WhatsAppConversation::paraNumero($telefone, $lead->site, [
                'phone' => $telefone,
                'profile_name' => $lead->nome,
                'last_message_at' => now(),
                'unread_count' => 0,
            ]);
            WhatsAppMessage::query()->create([
                'conversation_id' => $conversa->id,
                'wa_message_id' => $resposta['messages'][0]['id'] ?? null,
                'direction' => 'outbound',
                'type' => 'template',
                'body' => "Recuperação de carrinho (painel): {$nomeProduto}",
                'status' => 'sent',
                'sent_by' => 'sistema',
                'sent_at' => now(),
                'payload' => ['template' => $modelo],
            ]);
        } catch (Throwable $e) {
            Log::info('Histórico da recuperação de carrinho não gravado', ['lead' => $lead->id, 'erro' => $e->getMessage()]);
        }

        return self::OK;
    }

    /** Celular brasileiro com DDI 55, ou null. */
    public static function telefone(?string $telefone): ?string
    {
        $d = preg_replace('/\D/', '', (string) $telefone);
        if (strlen($d) === 10 || strlen($d) === 11) {
            $d = '55'.$d;
        }

        return preg_match('/^55\d{10,11}$/', $d) ? $d : null;
    }

    private static function primeiroNome(?string $nome): string
    {
        $primeiro = trim(explode(' ', trim((string) $nome))[0] ?? '');

        return $primeiro !== '' ? mb_convert_case(mb_strtolower($primeiro), MB_CASE_TITLE) : 'tudo bem';
    }
}
