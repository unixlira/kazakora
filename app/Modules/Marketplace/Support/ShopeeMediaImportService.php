<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\ProductImageOptimizer;
use App\Modules\Marketplace\Drivers\ShopeeDriver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Baixa foto/vídeo do anúncio Shopee de um produto que ainda não tem mídia
 * própria — extraído de App\Console\Commands\ImportShopeeProductMedia
 * (2026-08-16) pra ser reaproveitado também por
 * ShopeeImportMissingProducts (produto recém-cadastrado a partir de um
 * anúncio Shopee já nasce com foto/vídeo, não só o cadastro em si). Nunca
 * sobrescreve mídia já existente — mesma regra das duas chamadas.
 */
class ShopeeMediaImportService
{
    public function __construct(private readonly ShopeeDriver $driver) {}

    /**
     * @return array{images: bool, video: bool} true pro que foi
     *                                            efetivamente importado
     */
    public function importMissing(Product $product, string $externalId): array
    {
        $needsImages = $product->images()->count() === 0;
        $needsVideo = $product->video_path === null;

        if (! $needsImages && ! $needsVideo) {
            return ['images' => false, 'video' => false];
        }

        $media = $this->driver->fetchItemMedia($externalId);

        $importedImages = false;

        if ($needsImages && $media['images']) {
            foreach (array_values($media['images']) as $position => $url) {
                $path = $this->downloadTo("products/{$product->id}", $url);

                if (! $path) {
                    continue;
                }

                $path = ProductImageOptimizer::optimize($path) ?? $path;

                $product->images()->create([
                    'path' => $path,
                    'position' => $position,
                    'is_primary' => $position === 0,
                ]);
            }

            $importedImages = $product->images()->count() > 0;
        }

        $importedVideo = false;

        if ($needsVideo && $media['video']) {
            $path = $this->downloadTo("products/{$product->id}/video", $media['video']['url']);

            if ($path) {
                $product->update([
                    'video_path' => $path,
                    'video_duration_seconds' => $media['video']['duration'] ?? $product->video_duration_seconds,
                ]);
                $importedVideo = true;
            }
        }

        return ['images' => $importedImages, 'video' => $importedVideo];
    }

    private function downloadTo(string $directory, string $url): ?string
    {
        try {
            $response = Http::timeout(60)->get($url);
        } catch (\Throwable $exception) {
            Log::channel('shopee')->warning('shopee.media_import.download_failed', ['url' => $url, 'message' => $exception->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $extension = $this->guessExtension((string) $response->header('Content-Type'), $url);
        $path = "{$directory}/".Str::random(24).".{$extension}";

        Storage::disk('public')->put($path, $response->body());

        return $path;
    }

    private function guessExtension(string $contentType, string $url): string
    {
        $fromType = match (true) {
            str_contains($contentType, 'jpeg') => 'jpg',
            str_contains($contentType, 'png') => 'png',
            str_contains($contentType, 'webp') => 'webp',
            str_contains($contentType, 'mp4') => 'mp4',
            str_contains($contentType, 'quicktime') => 'mov',
            default => null,
        };

        if ($fromType) {
            return $fromType;
        }

        $fromUrl = strtolower((string) pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));

        return in_array($fromUrl, ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'mov'], true) ? $fromUrl : 'jpg';
    }
}
