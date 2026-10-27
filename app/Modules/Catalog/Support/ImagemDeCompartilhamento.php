<?php

namespace App\Modules\Catalog\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Foto do og:image da página de produto (pedido do Lira em 27/10/2026: link
 * colado no WhatsApp saía sem imagem). O WhatsApp descarta a prévia quando a
 * imagem é pesada (em 27/10, 3 de 94 produtos passavam de 600 KB, um PNG com
 * 1,9 MB). Usa a foto original quando ela é leve; senão a média (WebP de até
 * 1200 px, gerada no upload); se nenhuma for leve, fica a original mesmo.
 */
class ImagemDeCompartilhamento
{
    /** Acima disso o WhatsApp costuma não mostrar a foto na prévia. */
    public const LIMITE_BYTES = 300 * 1024;

    /**
     * @param  array<int, array<string, mixed>>  $imagens  images do produto (url, media_url...)
     */
    public static function escolher(array $imagens): ?string
    {
        $primeira = collect($imagens)->first(fn ($imagem) => filled($imagem['url'] ?? null));
        if (! $primeira) {
            return null;
        }

        $original = url((string) $primeira['url']);
        $candidatas = array_values(array_unique(array_filter([
            $original,
            filled($primeira['media_url'] ?? null) ? url((string) $primeira['media_url']) : null,
        ])));

        foreach ($candidatas as $candidata) {
            $tamanho = self::tamanhoLocal($candidata);
            if ($tamanho === null || $tamanho <= self::LIMITE_BYTES) {
                return $candidata;
            }
        }

        return $original;
    }

    /** Tamanho do arquivo em storage/app/public; null quando não é do disco local. */
    private static function tamanhoLocal(string $url): ?int
    {
        $caminho = (string) parse_url($url, PHP_URL_PATH);
        if (! str_starts_with($caminho, '/storage/')) {
            return null;
        }

        $relativo = rawurldecode(substr($caminho, strlen('/storage/')));
        if (str_contains($relativo, '..')) {
            return null;
        }

        $disco = Storage::disk('public');

        return $disco->exists($relativo) ? (int) $disco->size($relativo) : null;
    }
}
