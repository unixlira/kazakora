<?php

namespace App\Modules\WhatsApp\Support;

use Illuminate\Support\Str;

/**
 * Saudação da Manuela (pedido 2026-10-20): cliente disse "bom dia", ela
 * responde "bom dia"; "boa tarde" com "boa tarde"; "boa noite" com "boa
 * noite". Sem saudação de horário, "Oi" ou "Olá". Vale no WhatsApp e no chat
 * de suporte do site.
 *
 * Apresentação (pedido 2026-10-20, mais tarde): depois da saudação vem
 * "Eu sou a Manuela do <loja>. Como posso te ajudar hoje?", sem o "tudo bem
 * com você?" que o Gemini puxava sozinho. "Tudo bem?" só se o cliente
 * perguntou primeiro.
 */
class ManuelaSaudacao
{
    /** Frase de apresentação que vem depois do "Bom dia"/"Oi". */
    public static function apresentacao(string $loja): string
    {
        return "Eu sou a Manuela do {$loja}. Como posso te ajudar hoje?";
    }

    public static function regra(string $loja): string
    {
        return 'Saudação: se o cliente disser "bom dia", responda começando com "Bom dia"; se disser "boa tarde", com "Boa tarde"; se disser "boa noite", com "Boa noite". Repita a saudação que ele usou, mesmo que o relógio indique outro período. Se ele não usar saudação de horário, cumprimente com "Oi" ou "Olá" e nunca puxe "bom dia", "boa tarde" ou "boa noite" por conta própria. Cumprimente só quando ele cumprimentar ou na primeira resposta da conversa. '
            .'Apresentação: na primeira resposta da conversa, logo depois da saudação, diga exatamente "'.self::apresentacao($loja).'" (ex: "Boa tarde! '.self::apresentacao($loja).'"). Se ele já mandou a dúvida junto com o cumprimento, diga só "Eu sou a Manuela do '.$loja.'." e responda a dúvida. Depois disso não se apresente de novo. '
            .'Nunca pergunte "tudo bem?", "tudo bem com você?" ou "como vai?" por conta própria. Só se o cliente perguntar primeiro: aí responda que está tudo bem e devolva a pergunta.';
    }

    /** "Bom dia", "Boa tarde", "Boa noite" ou null se o cliente não usou nenhuma. */
    public static function doCliente(string $mensagem): ?string
    {
        $texto = Str::lower(Str::ascii($mensagem));

        return match (true) {
            (bool) preg_match('/\bbo[am]\s*dia\b/', $texto) => 'Bom dia',
            (bool) preg_match('/\bboa\s*tarde\b/', $texto) => 'Boa tarde',
            (bool) preg_match('/\bboa\s*noite\b/', $texto) => 'Boa noite',
            default => null,
        };
    }

    /** Troca o "Oi"/"Olá" do começo da resposta pela saudação que o cliente usou. */
    public static function espelhar(string $resposta, string $mensagemDoCliente): string
    {
        $saudacao = self::doCliente($mensagemDoCliente);

        if ($saudacao === null) {
            return $resposta;
        }

        $trocada = preg_replace('/^(oi|ol[aá])\b/iu', $saudacao, $resposta, 1, $trocas);

        return $trocas > 0 ? $trocada : $saudacao.'! '.$resposta;
    }

    /** O cliente perguntou "tudo bem?" (ou "tudo bom?", "como vai?", "como você está?"). */
    public static function clientePerguntouComoVai(string $mensagem): bool
    {
        return (bool) preg_match('/\b(tudo|td)\s*(bem|bom|certo|joia|ok)\b|\bcomo\s+(vai|voce\s+esta|vc\s+esta|esta)\b|\be\s+(ai|voce|vc)\s*\?/', Str::lower(Str::ascii($mensagem)));
    }

    /**
     * Garantia fora da IA: tira o "tudo bem?" / "tudo bem com você?" que a
     * Manuela puxou sem o cliente ter perguntado. "Tudo bem, vou ver" (sem
     * interrogação) fica.
     */
    public static function semTudoBem(string $resposta, string $mensagemDoCliente): string
    {
        if (self::clientePerguntouComoVai($mensagemDoCliente)) {
            return $resposta;
        }

        // "Oi, tudo bem? Eu sou..." vira "Oi! Eu sou...".
        $limpa = preg_replace_callback('/\s*(,)?\s*(?:e\s+)?(?:tudo\s+(?:bem|bom|certo|joia)|como\s+vai|como\s+(?:voc[eê]|vc)\s+est[aá])(?:\s+com\s+(?:voc[eê]|vc))?(?:\s*,\s*\p{L}+)?\s*\?+\s*(?:[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]\x{FE0F}?\s*)?/iu', fn (array $m) => ($m[1] ?? '') === ',' ? '! ' : ' ', $resposta);
        $limpa = trim((string) preg_replace('/[ \t]{2,}/', ' ', (string) $limpa));
        $limpa = (string) preg_replace('/\s+([!.,?])/u', '$1', $limpa);

        return $limpa !== '' ? $limpa : $resposta;
    }
}
