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

        $texto = $this->validar($texto);

        // Avaliação obrigatória (pedido 2026-10-11: "PRECISA SEMPRE AVALIAR"):
        // um segundo passo revisa a resposta antes de ela ir a público. Sem
        // aprovação explícita, não vai — e entra o texto pronto.
        if ($texto === null || ! $this->aprovada($pedido, $texto)) {
            return null;
        }

        return $texto;
    }

    private function aprovada(string $pedido, string $resposta): bool
    {
        try {
            $bruto = $this->gemini->generate(
                (string) config('services.gemini.chat_model'),
                [['role' => 'user', 'parts' => [['text' => $pedido."\n\nResposta proposta da loja:\n".$resposta]]]],
                <<<'TXT'
Você revisa, antes de publicar, a resposta de uma loja a uma avaliação pública na Shopee. A resposta NÃO pode ser editada depois de publicada, então seja rigoroso.
Aprove SOMENTE se TODAS forem verdadeiras:
1. Está completa: nenhuma frase cortada ou sem sentido, termina de forma natural.
2. Português do Brasil correto, sem erro de digitação.
3. Combina com a nota e o comentário (não agradece com entusiasmo uma reclamação; não pede desculpa por um elogio).
4. Não promete reembolso, troca, estorno ou devolução; não tem link, telefone, e-mail ou outra plataforma.
5. Tom gentil e profissional, sem exagero, que você publicaria com orgulho.
Responda SOMENTE com JSON: {"aprovada": true ou false, "motivo": "curto"}
TXT,
                1024,
                GeminiUsageLog::PURPOSE_REVIEW,
            );
        } catch (Throwable $e) {
            Log::channel('reviews')->warning('Revisor da resposta de avaliação falhou', ['erro' => mb_substr($e->getMessage(), 0, 180)]);

            return false;
        }

        $json = json_decode(trim((string) preg_replace('/^```(?:json)?|```$/m', '', trim($bruto))), true);
        $ok = is_array($json) && ($json['aprovada'] ?? null) === true;

        if (! $ok) {
            Log::channel('reviews')->info('Resposta de avaliação reprovada pelo revisor', ['resposta' => $resposta, 'motivo' => $json['motivo'] ?? mb_substr($bruto, 0, 160)]);
        }

        return $ok;
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
        // Faixas de emoji explícitas: o PCRE da VPS não conhece \p{Extended_Pictographic}.
        if (! preg_match('/([.!?…]|[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{2764}\x{FE0F}])$/u', $texto)) {
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
