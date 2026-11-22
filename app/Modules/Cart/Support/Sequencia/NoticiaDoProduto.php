<?php

namespace App\Modules\Cart\Support\Sequencia;

use App\Modules\Catalog\Models\Product;
use App\Modules\WhatsApp\Models\GeminiUsageLog;
use App\Modules\WhatsApp\Services\GeminiClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Uma notícia com base no produto" dos disparos 2 a 6 da sequência de
 * carrinho abandonado (pedido do Lira 21/11/2026). Cada disparo tem um
 * assunto; o Gemini escreve até ~300 caracteres a partir do nome, categoria
 * e descrição do produto. Regras: sem preço, sem desconto/cupom/promoção,
 * sem promessa de saúde ou resultado, sem inventar especificação. Texto que
 * fura a regra (ou Gemini fora do ar) vira o texto de reserva neutro.
 */
class NoticiaDoProduto
{
    public const MAXIMO = 300;

    /** Assunto de cada disparo de notícia (2 a 6). */
    public const ASSUNTOS = [
        2 => 'dica',
        3 => 'curiosidade',
        4 => 'cuidado',
        5 => 'para_quem',
        6 => 'escolha',
    ];

    private const PEDIDO_DO_ASSUNTO = [
        'dica' => 'uma dica prática de uso',
        'curiosidade' => 'uma curiosidade interessante sobre esse tipo de produto ou sobre o jeito de usar',
        'cuidado' => 'um cuidado de conservação, limpeza ou manutenção',
        'para_quem' => 'para quem ou para que situação ele faz mais sentido (inclusive como presente)',
        'escolha' => 'o que observar na hora de escolher esse tipo de produto',
    ];

    /** Palavras que nunca podem sair (pedido do Lira: sequência SEM desconto). */
    public const PROIBIDAS = '/desconto|cupom|cupo[mn]s|promo[cç]|oferta|liquida|barat|pre[cç]o|r\$|%|gr[aá]tis|frete|black ?friday|off\b|\bcur(a|ar)\b|emagre|garant[ei]|https?:|www\./iu';

    public function __construct(private readonly GeminiClient $gemini)
    {
    }

    public static function assunto(int $numero): string
    {
        return self::ASSUNTOS[$numero] ?? 'dica';
    }

    /** @return array{texto: string, fonte: string} fonte = gemini|reserva */
    public function escrever(?Product $produto, int $numero): array
    {
        $assunto = self::assunto($numero);
        $nome = self::nomeCurto($produto);

        if ($produto && $this->gemini->isConfigured()) {
            try {
                $texto = $this->gemini->generate(
                    (string) config('services.gemini.chat_model'),
                    [['role' => 'user', 'parts' => [['text' => $this->pedido($produto, $assunto)]]]],
                    $this->instrucoes(),
                    // O modelo gasta parte do limite "pensando" (ver RespostaAvaliacaoIa).
                    2048,
                    GeminiUsageLog::PURPOSE_CARRINHO,
                );
                $texto = self::validar($texto);
                if ($texto !== null) {
                    return ['texto' => $texto, 'fonte' => 'gemini'];
                }
            } catch (Throwable $e) {
                Log::warning('carrinho_sequencia_gemini_falhou', ['erro' => mb_substr($e->getMessage(), 0, 180)]);
            }
        }

        return ['texto' => self::reserva($nome, $assunto), 'fonte' => 'reserva'];
    }

    /** Texto limpo e dentro das regras, ou null. Variável de template não aceita quebra de linha. */
    public static function validar(?string $texto): ?string
    {
        $texto = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $texto)), " \"'“”");

        if ($texto === '' || mb_strlen($texto) > self::MAXIMO + 40 || preg_match(self::PROIBIDAS, $texto)) {
            return null;
        }

        return mb_strlen($texto) > self::MAXIMO ? rtrim(mb_substr($texto, 0, self::MAXIMO - 1)).'…' : $texto;
    }

    public static function reserva(string $nome, string $assunto): string
    {
        return match ($assunto) {
            'curiosidade' => "Você sabia? Muita gente escolhe o {$nome} depois de olhar com calma os detalhes da descrição. Vale dar uma olhada no que ele traz.",
            'cuidado' => "Cuidar bem do {$nome} ajuda ele a durar mais. Na página do produto estão as informações para usar e guardar do jeito certo.",
            'para_quem' => "Para você ou para presentear? Veja na página para quem o {$nome} faz mais sentido no dia a dia.",
            'escolha' => "Na dúvida na hora de escolher? Confira as medidas e os detalhes do {$nome} na página antes de decidir.",
            default => "Uma dica sobre o {$nome}: antes do primeiro uso, leia a descrição completa na página para aproveitar cada detalhe.",
        };
    }

    public static function nomeCurto(?Product $produto): string
    {
        $nome = trim((string) preg_replace('/\s+/u', ' ', (string) ($produto?->name ?: 'produto que você escolheu')));

        return Str::limit($nome, 60, '…');
    }

    private function pedido(Product $produto, string $assunto): string
    {
        $descricao = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $produto->description)));

        return 'Produto: '.$produto->name."\n"
            .'Categoria: '.($produto->category?->name ?: 'não informada')."\n"
            .'Descrição: '.(mb_substr($descricao, 0, 2000) ?: '(sem descrição)')."\n"
            .'Assunto da mensagem: '.self::PEDIDO_DO_ASSUNTO[$assunto];
    }

    private function instrucoes(): string
    {
        return <<<'TXT'
Você escreve uma mensagem curta de uma loja on-line para um cliente que deixou este produto no carrinho. A mensagem é uma "notícia" útil sobre o produto, no assunto pedido.
Regras obrigatórias:
- Português do Brasil, tom simpático e natural, 1 a 3 frases, no máximo 280 caracteres, sem emojis em excesso (no máximo 1), sem hashtags, sem saudação e sem assinatura.
- Use SOMENTE o que está no nome, na categoria e na descrição. Não invente medida, material, potência, garantia ou qualquer especificação.
- NUNCA fale de preço, valor, desconto, cupom, promoção, oferta, frete ou condição de pagamento.
- NUNCA prometa resultado, benefício de saúde, cura, emagrecimento ou garantia.
- Sem link, sem telefone, sem e-mail.
Responda só com o texto da mensagem.
TXT;
    }
}
