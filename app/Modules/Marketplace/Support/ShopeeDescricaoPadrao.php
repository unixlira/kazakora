<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\WhatsApp\Models\GeminiUsageLog;
use App\Modules\WhatsApp\Services\GeminiClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Padrão de descrição dos anúncios da Shopee (pedido do Lira, 2026-10-17):
 * título em caixa alta com emoji, parágrafo de abertura, e as seções
 * 🔥 PRINCIPAIS VANTAGENS, 📋 ESPECIFICAÇÕES TÉCNICAS, 📦 CONTEÚDO DA
 * EMBALAGEM e o fecho 🛒. Modelo: anúncios da Caixa Sanfonada e da Máquina de
 * Algodão Doce. Todo anúncio novo sai por aqui; descrição que já está no
 * padrão passa sem mudança.
 *
 * Ordem de tentativa: já conforme → Gemini reescreve (só com fatos do
 * cadastro) → montagem própria a partir do texto do cadastro. Nunca lança
 * exceção e nunca devolve texto sem emoji.
 */
class ShopeeDescricaoPadrao
{
    private const LIMITE_SHOPEE = 3000;

    /** Seções obrigatórias (rótulo sem emoji) que o texto precisa ter. */
    private const SECOES = ['PRINCIPAIS VANTAGENS', 'ESPECIFICAÇÕES TÉCNICAS', 'CONTEÚDO DA EMBALAGEM'];

    public function __construct(private readonly GeminiClient $gemini) {}

    public function conforme(string $texto): bool
    {
        if (! $this->temEmoji($texto) || mb_strlen($texto) > self::LIMITE_SHOPEE) {
            return false;
        }

        foreach (self::SECOES as $secao) {
            if (! preg_match('/\p{So}\x{FE0F}?\s*'.preg_quote($secao, '/').'/u', mb_strtoupper($texto))) {
                return false;
            }
        }

        return true;
    }

    public function temEmoji(string $texto): bool
    {
        return (bool) preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $texto);
    }

    public function padronizar(Product $product): string
    {
        $original = trim((string) $product->description);

        if ($this->conforme($original)) {
            return $original;
        }

        if ($this->gemini->isConfigured()) {
            try {
                $texto = $this->limpar($this->gemini->generate(
                    (string) config('services.gemini.chat_model'),
                    [['role' => 'user', 'parts' => [['text' => $this->prompt($product, $original)]]]],
                    'Você é redator de anúncios da Shopee Brasil. Responde SOMENTE com o texto da descrição, sem markdown.',
                    3000,
                    GeminiUsageLog::PURPOSE_AD_CONTENT,
                ));

                if ($this->conforme($texto)) {
                    return $texto;
                }
            } catch (Throwable $exception) {
                Log::warning('shopee.descricao_padrao_gemini_falhou', ['product_id' => $product->id, 'erro' => Str::limit($exception->getMessage(), 500)]);
            }
        }

        return $this->montar($product, $original);
    }

    /** Montagem sem IA: só reaproveita o que o cadastro já diz. */
    public function montar(Product $product, string $original): string
    {
        $nome = mb_strtoupper(trim((string) $product->name));
        $linhas = collect(preg_split('/\r?\n|(?<=[.!?])\s+/u', strip_tags($original)) ?: [])
            ->map(fn ($linha) => trim(preg_replace('/^[\p{So}\x{FE0F}\-\*•✔✅\s]+/u', '', (string) $linha)))
            ->filter(fn ($linha) => mb_strlen($linha) >= 8)
            ->unique()
            ->values();

        $abertura = $linhas->take(2)->implode(' ');
        $vantagens = $linhas->slice(2, 6)->map(fn ($linha) => '✅ '.rtrim($linha, '.'))->implode("\n");

        $blocos = ["✨ {$nome}"];

        if ($abertura !== '') {
            $blocos[] = $abertura;
        }

        $blocos[] = "🔥 PRINCIPAIS VANTAGENS\n".($vantagens !== '' ? $vantagens : '✅ Produto novo, com envio rápido e embalagem segura.');
        $blocos[] = "📋 ESPECIFICAÇÕES TÉCNICAS\nProduto: ".trim((string) $product->name);
        $blocos[] = "📦 CONTEÚDO DA EMBALAGEM\n✅ 1 ".trim((string) $product->name);
        $blocos[] = '🚚 ENVIO RÁPIDO!';
        $blocos[] = "🛒 Garanta o seu e compre com segurança na KazaKora!";

        return $this->limpar(implode("\n\n", $blocos));
    }

    private function prompt(Product $product, string $original): string
    {
        return <<<TXT
Reescreva a descrição do produto abaixo para a Shopee, EXATAMENTE neste padrão (texto puro, uma linha em branco entre seções, sem markdown, sem HTML, sem travessão):

✨ NOME DO PRODUTO EM CAIXA ALTA

Parágrafo curto de abertura (2 a 3 frases).

🔥 PRINCIPAIS VANTAGENS
✅ Benefício: explicação curta.
(4 a 6 linhas)

🏠 INDICAÇÕES DE USO
✔ situação de uso
(3 a 6 linhas)

📋 ESPECIFICAÇÕES TÉCNICAS
Rótulo: valor
(só o que está no cadastro)

📏 DIMENSÕES DA EMBALAGEM
(só se houver medidas no cadastro)

📦 CONTEÚDO DA EMBALAGEM
✅ 1 Nome do produto

🚚 ENVIO RÁPIDO!

🛒 Chamada final para a compra.

Regras: use somente fatos do cadastro; não invente medida, potência, voltagem, garantia ou certificação; nada de promessa não comprovada; português do Brasil; no máximo 2800 caracteres; emojis obrigatórios em todos os títulos de seção.

Nome: {$product->name}
Cadastro atual:
{$original}
TXT;
    }

    private function limpar(string $texto): string
    {
        $texto = str_replace(['**', '__', '```', '—', '–'], ['', '', '', ', ', ', '], strip_tags($texto));
        $texto = trim((string) preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $texto)));

        return mb_strlen($texto) > self::LIMITE_SHOPEE ? rtrim(mb_substr($texto, 0, self::LIMITE_SHOPEE)) : $texto;
    }
}
