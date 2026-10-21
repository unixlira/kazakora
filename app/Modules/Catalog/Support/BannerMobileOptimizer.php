<?php

namespace App\Modules\Catalog\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Imagem mobile do Hero (pedido 2026-10-15): WebP e três larguras
 * (480, 828 e 1080 px) pro celular baixar só o tamanho que a tela pede.
 * Nunca amplia: se o original é menor que a largura, ela não é gerada.
 * Sem GD/WebP, ou arquivo que não é imagem, o original fica como está.
 *
 * Desempenho (pedido 2026-10-21, PageSpeed verde sem mudar o visual): a
 * imagem do computador também ganha WebP (1280, 1920 e 2560 px). Nesse caso
 * o original FICA no disco e o WebP vai ao lado, com o mesmo nome
 * ("abc.png" => "abc.webp"); o srcset acha o WebP sozinho.
 */
class BannerMobileOptimizer
{
    public const LARGURAS = [480, 828, 1080];

    public const LARGURAS_PC = [1280, 1920, 2560];

    private const QUALIDADE = 82;

    /**
     * @return string|null caminho relativo do arquivo principal (.webp), ou
     *                     null se não foi possível otimizar (o original fica).
     */
    public static function otimizar(string $path, string $disk = 'public', array $larguras = self::LARGURAS, bool $manterOriginal = false): ?string
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
        $maior = min($largura, max($larguras));

        // Original já em WebP e mantido: não sobrescreve, só gera as larguras menores.
        if (! ($manterOriginal && $principal === $path)) {
            self::gravar($origem, $largura, $altura, $maior, $storage->path($principal));
        }
        foreach ($larguras as $alvo) {
            if ($alvo < $maior) {
                self::gravar($origem, $largura, $altura, $alvo, $storage->path(self::variante($principal, $alvo)));
            }
        }
        imagedestroy($origem);

        if ($principal !== $path && ! $manterOriginal) {
            @unlink($completo);
        }

        return $principal;
    }

    /** "banners/abc.webp" + 480 => "banners/abc-480w.webp" */
    public static function variante(string $path, int $largura): string
    {
        return preg_replace('/\.(\w+)$/', "-{$largura}w.$1", $path);
    }

    /** "banners/abc.png" => "banners/abc.webp" (o WebP gerado ao lado do original). */
    public static function webpAoLado(string $path): string
    {
        return preg_replace('/\.\w+$/', '', $path).'.webp';
    }

    /**
     * Larguras que existem em disco, da menor pra maior (inclui o principal).
     * Original que não é WebP usa o WebP gerado ao lado dele, se existir.
     */
    public static function srcset(string $path, string $disk = 'public', array $larguras = self::LARGURAS): ?string
    {
        $storage = Storage::disk($disk);
        if (! str_ends_with($path, '.webp')) {
            $path = self::webpAoLado($path);
            if (! $storage->exists($path)) {
                return null;
            }
        }

        $itens = [];
        foreach ($larguras as $alvo) {
            $variante = self::variante($path, $alvo);
            if ($storage->exists($variante)) {
                $itens[] = asset('storage/'.$variante)." {$alvo}w";
            }
        }
        if (! $itens) {
            return null;
        }

        $info = @getimagesize($storage->path($path));
        $itens[] = asset('storage/'.$path).' '.($info[0] ?? max($larguras)).'w';

        return implode(', ', $itens);
    }

    public static function apagar(?string $path, string $disk = 'public'): void
    {
        if (! $path) {
            return;
        }
        $storage = Storage::disk($disk);
        $storage->delete($path);
        // Junto com o original vão o WebP ao lado dele e as larguras dos dois.
        $webp = self::webpAoLado($path);
        $storage->delete($webp);
        foreach (array_unique([...self::LARGURAS, ...self::LARGURAS_PC]) as $alvo) {
            $storage->delete(self::variante($path, $alvo));
            $storage->delete(self::variante($webp, $alvo));
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
