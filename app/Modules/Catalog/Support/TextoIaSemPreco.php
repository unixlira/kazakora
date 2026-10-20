<?php

namespace App\Modules\Catalog\Support;

/**
 * Regra global dos textos gerados por IA (pedido do Lira, 2026-10-20): nunca
 * mencionar preço, parcelamento ou valor em dinheiro. O preço muda e cada
 * canal mostra o seu; texto com preço fica errado no dia seguinte.
 *
 * regra() vai no prompt; semPreco() é a garantia: tira do texto devolvido
 * as frases (ou linhas) que ainda falam de preço.
 */
class TextoIaSemPreco
{
    private const PADRAO = '/R\$|\d\s*reais\b|\breais\b|\bcentavos?\b|\bpre[çc]os?\b|\bparcel|\bjuros\b|\b\d{1,2}\s*x\s*(de|sem)\b|\bà\s+vista\b|\bcusta(m)?\s+(só|apenas|menos)\b/iu';

    public static function regra(): string
    {
        return 'Preço (proibido em todo texto): nunca mencione preço, valor em reais (R$), parcelamento, juros, "à vista" nem qualquer quantia em dinheiro; o preço muda e cada canal mostra o seu.';
    }

    public static function mencionaPreco(string $texto): bool
    {
        return preg_match(self::PADRAO, $texto) === 1;
    }

    /** Tira as frases que falam de preço; linhas e o resto do texto ficam como estão. */
    public static function semPreco(string $texto): string
    {
        if (! self::mencionaPreco($texto)) {
            return $texto;
        }

        $linhas = array_map(function (string $linha): string {
            if (! self::mencionaPreco($linha)) {
                return $linha;
            }

            $frases = preg_split('/(?<=[.!?])\s+/u', $linha) ?: [];

            return trim(implode(' ', array_filter($frases, fn (string $frase) => ! self::mencionaPreco($frase))));
        }, explode("\n", $texto));

        $limpo = implode("\n", $linhas);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $limpo));
    }
}
