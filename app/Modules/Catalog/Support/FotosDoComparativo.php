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

    /**
     * Blocos da descrição com vídeo próprio e link para outro produto (pedido do Lira 26/11/2026:
     * depois de "Bateria arriada", o vídeo do Kit Macaco Elétrico com o link para a página dele).
     * No bloco: video (caminho no disco público), poster (idem, a capa antes do play), link_produto
     * (id do produto) e link_texto. Sai video_url, poster_url e link {texto, url}; o link só sai
     * quando $urlDoProduto devolve a página (produto fora da loja fica sem link).
     *
     * @param  callable(int): ?string  $urlDoProduto
     */
    public static function blocosComVideo(?array $blocos, callable $urlDoProduto): array
    {
        return array_map(function ($bloco) use ($urlDoProduto) {
            if (! is_array($bloco)) {
                return $bloco;
            }
            if (filled($bloco['video'] ?? null)) {
                $bloco['video_url'] = self::url($bloco['video']);
                $bloco['poster_url'] = self::url($bloco['poster'] ?? null);
            }
            if ((int) ($bloco['link_produto'] ?? 0) > 0) {
                $url = $urlDoProduto((int) $bloco['link_produto']);
                $bloco['link'] = $url ? ['texto' => (string) ($bloco['link_texto'] ?? 'Ver o produto'), 'url' => $url] : null;
            }
            unset($bloco['video'], $bloco['poster'], $bloco['link_produto'], $bloco['link_texto']);

            return $bloco;
        }, array_values($blocos ?? []));
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
