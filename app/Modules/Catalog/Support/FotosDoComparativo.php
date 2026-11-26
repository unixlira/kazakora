<?php

namespace App\Modules\Catalog\Support;

/**
 * Fotos de cada linha do "Por que escolher" (pedido do Lira 26/11/2026, oferta do Compressor
 * 4 em 1): comparativo.imagens[linha] = [foto da solução comum, foto com o produto]. O comando
 * kits:escada guarda o caminho no disco público (products/<id>/comparativo/...); a página da
 * KazaKora e a API do FerraKora recebem a URL. URL pronta passa igual.
 */
class FotosDoComparativo
{
    public static function comUrls(?array $comparativo): ?array
    {
        if (empty($comparativo['imagens']) || ! is_array($comparativo['imagens'])) {
            return $comparativo;
        }

        $comparativo['imagens'] = array_map(fn ($par) => array_map(
            fn ($foto) => self::url($foto),
            is_array($par) ? array_values($par) : [],
        ), array_values($comparativo['imagens']));

        return $comparativo;
    }

    /** Caminho do disco público (ou URL) para URL; vazio vira null. */
    public static function url(mixed $foto): ?string
    {
        if (! is_string($foto) || trim($foto) === '') {
            return null;
        }

        return preg_match('#^https?://#i', $foto) ? $foto : asset('storage/'.ltrim(preg_replace('#^storage/#', '', $foto), '/'));
    }
}
