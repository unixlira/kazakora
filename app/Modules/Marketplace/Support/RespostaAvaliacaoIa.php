<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\WhatsApp\Models\GeminiUsageLog;
use App\Modules\WhatsApp\Services\GeminiClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resposta de avaliação da Shopee escrita pelo Gemini (pedido 2026-10-11:
 * "o gemini responder as avaliações da shopee"). Lê a nota, o comentário e
 * o produto e escreve uma resposta única. Qualquer resposta que fuja das
 * regras (link, telefone, promessa de reembolso, tamanho) é descartada e o
 * AutoReplyShopeeReviewsService cai no texto pronto.
 */
class RespostaAvaliacaoIa
{
    /** A Shopee aceita até 500 caracteres na resposta. */
    private const MAXIMO = 480;

    public function __construct(private readonly GeminiClient $gemini)
    {
    }

    public function escrever(int $nota, string $comentario, ?string $produto): ?string
    {
        if (! $this->gemini->isConfigured()) {
            return null;
        }

        $pedido = "Nota: ".($nota > 0 ? "{$nota} de 5" : 'sem nota')."\n"
            .'Produto: '.($produto ?: 'não informado')."\n"
            .'Comentário do cliente: '.(trim($comentario) !== '' ? mb_substr(trim($comentario), 0, 1200) : '(sem texto)');

        try {
            $texto = $this->gemini->generate(
                (string) config('services.gemini.chat_model'),
                [['role' => 'user', 'parts' => [['text' => $pedido]]]],
                $this->instrucoes(),
                // O modelo gasta parte do limite "pensando": com 400 a
                // resposta saiu cortada no meio da frase (2026-10-11).
                4096,
                GeminiUsageLog::PURPOSE_REVIEW,
            );
        } catch (Throwable $e) {
            Log::channel('reviews')->warning('Gemini não respondeu a avaliação', ['erro' => mb_substr($e->getMessage(), 0, 180)]);

            return null;
        }

        return $this->validar($texto);
    }

    private function validar(string $texto): ?string
    {
        $texto = trim(preg_replace('/\s+/u', ' ', strip_tags(trim($texto, " \t\n\r\"'"))));

        $proibido = '/(https?:|www\.|\.com|@|whats|wa\.me|telefone|\d{4,}|reembols|devolv[ae]mos|estorn|garantimos|mercado livre|tiktok|amazon|kazakora\.com)/iu';

        if (mb_strlen($texto) < 20 || mb_strlen($texto) > self::MAXIMO || preg_match($proibido, $texto)) {
            return null;
        }

        // Frase tem que terminar: ponto, exclamação, interrogação ou emoji.
        // Resposta cortada no meio ("chegou dire") nunca vai para a Shopee.
        if (! preg_match('/([.!?…]|\p{Extended_Pictographic}|\x{FE0F})$/u', $texto)) {
            return null;
        }

        return $texto;
    }

    private function instrucoes(): string
    {
        return <<<'TXT'
Você responde, em nome da loja KazaKora, avaliações públicas de compradores na Shopee.
Escreva UMA resposta em português do Brasil, natural, gentil e curta (1 a 3 frases, no máximo 400 caracteres).
Regras:
- Cite algo concreto do comentário ou do produto quando houver; nunca soe como texto pronto.
- Nota 4 ou 5: agradeça de verdade e convide a seguir a loja para acompanhar novidades e promoções.
- Nota 1, 2, 3 ou sem nota: acolha o problema sem discutir nem culpar o cliente e peça para chamar a loja pelo chat da Shopee para resolvermos.
- Se for carregador turbo e reclamar de carga lenta, pode explicar que com a saúde da bateria abaixo de 80% o aparelho limita o turbo.
- NUNCA prometa reembolso, troca, estorno ou devolução; NUNCA coloque link, telefone, e-mail, número de pedido ou nome de outra plataforma.
- Não use o nome do cliente. Sem hashtags. No máximo 1 emoji.
Responda só com o texto da resposta, sem aspas.
TXT;
    }
}
