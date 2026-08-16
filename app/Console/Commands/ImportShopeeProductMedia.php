<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Support\ProductImageOptimizer;
use App\Modules\Marketplace\Drivers\ShopeeDriver;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Pedido explícito 2026-08-16: importa fotos e vídeo do anúncio da Shopee
 * pros produtos locais que ainda NÃO têm nenhuma foto/vídeo próprio — não
 * mexe em mais nenhum campo do produto, e não sobrescreve mídia já
 * existente (fotos/vídeo carregados manualmente pelo admin sempre têm
 * prioridade, esse comando só preenche o que está vazio). Comando manual
 * (`php artisan shopee:import-media`), não agendado — é uma importação de
 * catálogo, não algo que precise rodar de novo sozinho (diferente de
 * reviews:sync, que traz avaliação nova toda hora).
 */
class ImportShopeeProductMedia extends Command
{
    protected $signature = 'shopee:import-media {--product= : Importar só um product_id específico}';

    protected $description = 'Importa fotos e vídeo dos anúncios da Shopee pros produtos locais que ainda não têm mídia própria.';

    public function handle(ShopeeDriver $driver): int
    {
        $query = ProductChannelListing::query()
            ->with('product')
            ->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)
            ->whereNotNull('external_id')
            ->where('external_id', '!=', '');

        if ($productId = $this->option('product')) {
            $query->where('product_id', (int) $productId);
        }

        $listings = $query->get();

        $imagesImported = 0;
        $videosImported = 0;
        $skipped = 0;

        foreach ($listings as $listing) {
            $product = $listing->product;

            if (! $product) {
                continue;
            }

            $needsImages = $product->images()->count() === 0;
            $needsVideo = $product->video_path === null;

            if (! $needsImages && ! $needsVideo) {
                $skipped++;

                continue;
            }

            $media = $driver->fetchItemMedia($listing->external_id);

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

                if ($product->images()->count() > 0) {
                    $imagesImported++;
                }
            }

            if ($needsVideo && $media['video']) {
                $path = $this->downloadTo("products/{$product->id}/video", $media['video']['url']);

                if ($path) {
                    $product->update([
                        'video_path' => $path,
                        'video_duration_seconds' => $media['video']['duration'] ?? $product->video_duration_seconds,
                    ]);
                    $videosImported++;
                }
            }
        }

        $this->info("Fotos importadas em {$imagesImported} produto(s), vídeo importado em {$videosImported} produto(s), {$skipped} já tinham mídia própria (ignorados).");

        return self::SUCCESS;
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
