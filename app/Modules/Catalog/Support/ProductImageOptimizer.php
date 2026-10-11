<?php

namespace App\Modules\Catalog\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Resize + re-encode pra JPEG q82 (GD, sem dependência nova) — extraído de
 * ProductImageController::optimize() em 2026-08-16 pra ser reaproveitado
 * também por ImportShopeeProductMedia (fotos baixadas da Shopee passam
 * pelo mesmo tratamento das fotos que um admin sobe manualmente, mesmo
 * motivo original: fotos de fonte externa vêm grandes/pesadas sem
 * necessidade). Comportamento idêntico ao método original, só movido.
 */
class ProductImageOptimizer
{
    /** Longest side, in pixels, after resizing — plenty for zoomed product photos. */
    private const MAX_DIMENSION = 1600;

    private const JPEG_QUALITY = 82;

    /**
     * @return string|null caminho relativo novo (sempre .jpg), ou null se a
     *                      otimização não foi possível (sem GD, formato não
     *                      reconhecido) — o arquivo original é mantido.
     */
    public static function optimize(string $path, string $disk = 'public'): ?string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }

        $storage = Storage::disk($disk);
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

        $newPath = preg_replace('/\.\w+$/', '.jpg', $path);
        $newFullPath = $storage->path($newPath);

        imagejpeg($resized, $newFullPath, self::JPEG_QUALITY);

        imagedestroy($source);
        imagedestroy($resized);

        if ($newFullPath !== $fullPath) {
            @unlink($fullPath);
        }

        return $newPath;
    }
}
