<?php

namespace App\Support;

/**
 * Link de rastreio pela transportadora do código (pedido do Lira em
 * 2026-11-09: "cada tipo de etiqueta deve ter o rastreio correto"). A tela
 * de rastreio mandava todo código para os Correios; o #3978 saiu pela J&T
 * (888031040957845) pelo Melhor Envio e o cliente caía numa página dos
 * Correios sem nada.
 *
 * - Código no formato dos Correios (AB123456789BR, inclusive o Correios
 *   comprado pelo Melhor Envio): rastreamento dos Correios.
 * - Qualquer outro (J&T, Jadlog, Loggi, LATAM, Azul, Buslog...): Melhor
 *   Rastreio, o rastreio público do Melhor Envio, que reconhece a
 *   transportadora pelo código.
 * - Protocolo do Melhor Envio (ORD-...) não é rastreio: a etiqueta foi
 *   gerada mas a transportadora ainda não deu o código.
 */
final class LinkRastreio
{
    public static function correios(?string $codigo): bool
    {
        return (bool) preg_match('/^[A-Z]{2}\d{9}[A-Z]{2}$/', strtoupper(trim((string) $codigo)));
    }

    /** Código que vale mostrar ao cliente (null = ainda não há rastreio). */
    public static function codigo(?string $codigo): ?string
    {
        $codigo = trim((string) $codigo);

        return $codigo === '' || str_starts_with(strtoupper($codigo), 'ORD-') ? null : $codigo;
    }

    public static function url(?string $codigo): ?string
    {
        $codigo = self::codigo($codigo);
        if ($codigo === null) {
            return null;
        }

        return self::correios($codigo)
            ? 'https://rastreamento.correios.com.br/app/index.php?objetos='.strtoupper($codigo)
            : 'https://www.melhorrastreio.com.br/rastreio/'.rawurlencode($codigo);
    }
}
