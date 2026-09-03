<?php

namespace App\Modules\Catalog\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Gera a miniatura que a vitrine (ProductCard, carrinho) usa, ao lado da
 * imagem original — nunca no lugar dela.
 *
 * Performance 2026-09-03: a home servia a imagem de catálogo inteira
 * (1600px, ~150 KB cada, feita por ProductImageOptimizer) dentro de um card
 * que na tela tem ~250px de largura. Com 14 cards visíveis isso era 2,1 MB
 * de imagem pra mostrar o equivalente a uns 400 KB.
 *
 * A original fica intacta de propósito: é ela que o zoom da página de
 * produto usa e é ela que já foi publicada no Mercado Livre e na Shopee —
 * mexer nela quebraria anúncio que já está no ar. A miniatura é um arquivo
 * novo em `<pasta>/thumbs/`, e se a geração falhar o site simplesmente
 * continua usando a original (ProductImage::thumb_url cai pra url).
 */
class ProductImageThumbnailer
{
    /** Maior lado da miniatura. O card renderiza ~250px; 512 cobre tela 2x. */
    private const MAX_DIMENSION = 512;

    private const WEBP_QUALITY = 78;

    private const JPEG_QUALITY = 80;

    /**
     * @return string|null caminho relativo da miniatura, ou null quando não
     *                      foi possível gerar (sem GD, formato desconhecido,
     *                      arquivo sumido) — o chamador segue com a original
     */
    public static function generate(string $path, string $disk = 'public'): ?string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }

        $storage = Storage::disk($disk);

        if (! $storage->exists($path)) {
            return null;
        }

        $fullPath = $storage->path($path);
        $info = @getimagesize($fullPath);

        if (! $info) {
            return null;
        }

        [$width, $height, $type] = $info;

        $source = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($fullPath),
            IMAGETYPE_PNG => @imagecreatefrompng($fullPath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($fullPath) : null,
            default => null,
        };

        if (! $source) {
            return null;
        }

        $scale = min(1, self::MAX_DIMENSION / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        imagefill($resized, 0, 0, imagecolorallocate($resized, 255, 255, 255));
        imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        $useWebp = function_exists('imagewebp');
        $thumbPath = self::pathFor($path, $useWebp ? 'webp' : 'jpg');

        // Garante a pasta thumbs/ antes de escrever direto pelo GD.
        $storage->makeDirectory(dirname($thumbPath));

        $written = $useWebp
            ? @imagewebp($resized, $storage->path($thumbPath), self::WEBP_QUALITY)
            : @imagejpeg($resized, $storage->path($thumbPath), self::JPEG_QUALITY);

        imagedestroy($source);
        imagedestroy($resized);

        return $written ? $thumbPath : null;
    }

    /**
     * `products/139/abc.jpg` -> `products/139/thumbs/abc.webp`. Pasta
     * separada pra que um `ls` na pasta do produto continue mostrando só as
     * originais, e pra nunca haver risco de colisão de nome com elas.
     */
    private static function pathFor(string $path, string $extension): string
    {
        $directory = dirname($path);
        $name = pathinfo($path, PATHINFO_FILENAME);

        return ($directory === '.' ? '' : $directory.'/')."thumbs/{$name}.{$extension}";
    }
}
