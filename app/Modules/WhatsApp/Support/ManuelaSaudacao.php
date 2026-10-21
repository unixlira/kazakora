<?php

namespace App\Modules\WhatsApp\Support;

use Illuminate\Support\Str;

/**
 * Saudação da Manuela (pedido 2026-10-20): cliente disse "bom dia", ela
 * responde "bom dia"; "boa tarde" com "boa tarde"; "boa noite" com "boa
 * noite". Sem saudação de horário, "Oi" ou "Olá". Vale no WhatsApp e no chat
 * de suporte do site.
 */
class ManuelaSaudacao
{
    public const REGRA = 'Saudação: se o cliente disser "bom dia", responda começando com "Bom dia"; se disser "boa tarde", com "Boa tarde"; se disser "boa noite", com "Boa noite". Repita a saudação que ele usou, mesmo que o relógio indique outro período. Se ele não usar saudação de horário, cumprimente com "Oi" ou "Olá" e nunca puxe "bom dia", "boa tarde" ou "boa noite" por conta própria. Cumprimente só quando ele cumprimentar ou na primeira resposta da conversa.';

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
}
