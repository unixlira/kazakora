<?php

namespace App\Modules\Catalog\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Imagem mobile do Hero (pedido 2026-10-15): WebP e três larguras
 * (480, 828 e 1080 px) pro celular baixar só o tamanho que a tela pede.
 * Nunca amplia: se o original é menor que a largura, ela não é gerada.
 * Sem GD/WebP, ou arquivo que não é imagem, o original fica como está.
 */
class BannerMobileOptimizer
{
    public const LARGURAS = [480, 828, 1080];

    private const QUALIDADE = 82;

    /**
     * @return string|null caminho relativo do arquivo principal (.webp), ou
     *                     null se não foi possível otimizar (o original fica).
     */
    public static function otimizar(string $path, string $disk = 'public'): ?string
    {
        if (! function_exists('imagewebp') || ! function_exists('imagecreatetruecolor')) {
            return null;
        }

        $storage = Storage::disk($disk);
        $completo = $storage->path($path);
        $info = @getimagesize($completo);
        if (! $info) {
            return null;
        }

        [$largura, $altura, $tipo] = $info;
        $origem = match ($tipo) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($completo),
            IMAGETYPE_PNG => @imagecreatefrompng($completo),
            IMAGETYPE_WEBP => @imagecreatefromwebp($completo),
            default => null,
        };
        if (! $origem) {
            return null;
        }

        imagepalettetotruecolor($origem);
        imagealphablending($origem, true);
        imagesavealpha($origem, true);

        $base = preg_replace('/\.\w+$/', '', $path);
        $principal = $base.'.webp';
        $maior = min($largura, max(self::LARGURAS));

        self::gravar($origem, $largura, $altura, $maior, $storage->path($principal));
        foreach (self::LARGURAS as $alvo) {
            if ($alvo < $maior) {
                self::gravar($origem, $largura, $altura, $alvo, $storage->path(self::variante($principal, $alvo)));
            }
        }
        imagedestroy($origem);

        if ($principal !== $path) {
            @unlink($completo);
        }

        return $principal;
    }

    /** "banners/abc.webp" + 480 => "banners/abc-480w.webp" */
    public static function variante(string $path, int $largura): string
    {
        return preg_replace('/\.(\w+)$/', "-{$largura}w.$1", $path);
    }

    /** Larguras que existem em disco, da menor pra maior (inclui o principal). */
    public static function srcset(string $path, string $disk = 'public'): ?string
    {
        if (! str_ends_with($path, '.webp')) {
            return null;
        }

        $storage = Storage::disk($disk);
        $itens = [];
        foreach (self::LARGURAS as $alvo) {
            $variante = self::variante($path, $alvo);
            if ($storage->exists($variante)) {
                $itens[] = asset('storage/'.$variante)." {$alvo}w";
            }
        }
        if (! $itens) {
            return null;
        }

        $info = @getimagesize($storage->path($path));
        $itens[] = asset('storage/'.$path).' '.($info[0] ?? max(self::LARGURAS)).'w';

        return implode(', ', $itens);
    }

    public static function apagar(?string $path, string $disk = 'public'): void
    {
        if (! $path) {
            return;
        }
        $storage = Storage::disk($disk);
        $storage->delete($path);
        foreach (self::LARGURAS as $alvo) {
            $storage->delete(self::variante($path, $alvo));
        }
    }

    private static function gravar($origem, int $largura, int $altura, int $alvo, string $destino): void
    {
        $novaAltura = max(1, (int) round($altura * $alvo / $largura));
        $copia = imagecreatetruecolor($alvo, $novaAltura);
        imagealphablending($copia, false);
        imagesavealpha($copia, true);
        imagefill($copia, 0, 0, imagecolorallocatealpha($copia, 0, 0, 0, 127));
        imagecopyresampled($copia, $origem, 0, 0, 0, 0, $alvo, $novaAltura, $largura, $altura);
        imagewebp($copia, $destino, self::QUALIDADE);
        imagedestroy($copia);
    }
}
