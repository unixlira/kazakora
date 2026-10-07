<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Models\ProductChannelListing;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Completa o cadastro de um produto que nasceu de uma venda.
 *
 * Pedido explícito do usuário em 2026-10-07: chegou pedido de um produto
 * que não temos no Kazakora, ele tem que ser importado COMPLETO — dados,
 * informações, imagens e vídeo — e nunca ficar de fora. O
 * autoImportProduct() de cada driver cria só o essencial pro pedido andar
 * (nome, preço, estoque, SKU), dentro da transação do pedido; o resto vem
 * aqui, depois, sem atrasar a entrada da venda:
 *
 *  - descrição, marca, modelo e cor (driver->fetchItemContent());
 *  - GTIN no cadastro fiscal, quando ele já existe;
 *  - fotos (ProductMediaBackfillService, mesma regra de sempre);
 *  - vídeo, baixado pro disco como o upload do admin.
 *
 * Só preenche campo VAZIO: nunca sobrescreve o que alguém já cadastrou.
 * Idempotente — rodar de novo sobre um produto completo não faz nada.
 */
class ProductCompletionService
{
    private const CAMPOS = ['description', 'brand', 'model', 'color'];

    public function __construct(
        private readonly MarketplaceDriverManager $drivers,
        private readonly ProductMediaBackfillService $media,
    ) {}

    /**
     * @return array{fields: array<int, string>, images: int, video: bool}
     */
    public function complete(Product $product): array
    {
        $result = ['fields' => [], 'images' => 0, 'video' => false];

        $listings = ProductChannelListing::query()
            ->where('product_id', $product->id)
            ->whereNotNull('external_id')
            ->get();

        foreach ($listings as $listing) {
            $content = $this->conteudoDoCanal($product, $listing);

            if ($content === []) {
                continue;
            }

            $faltando = collect(self::CAMPOS)
                ->filter(fn ($campo) => blank($product->{$campo}) && filled($content[$campo] ?? null))
                ->mapWithKeys(fn ($campo) => [$campo => $content[$campo]])
                ->all();

            if ($faltando !== []) {
                $product->update($faltando);
                $result['fields'] = array_values(array_unique([...$result['fields'], ...array_keys($faltando)]));
            }

            $fiscal = $product->fiscalData()->first();

            if ($fiscal && blank($fiscal->gtin) && filled($content['gtin'] ?? null)) {
                $fiscal->update(['gtin' => $content['gtin']]);
                $result['fields'][] = 'gtin';
            }

            if (! $result['video'] && $product->video_path === null && isset($content['video']['url'])) {
                $result['video'] = $this->salvarVideo($product, $content['video']);
            }
        }

        try {
            $result['images'] = $this->media->fill($product);
        } catch (Throwable $exception) {
            Log::warning('catalog.product_completion.images_failed', ['product_id' => $product->id, 'message' => $exception->getMessage()]);
        }

        Log::info('catalog.product_completion.done', ['product_id' => $product->id, ...$result]);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function conteudoDoCanal(Product $product, ProductChannelListing $listing): array
    {
        try {
            return $this->drivers->driver($listing->channel)->fetchItemContent(
                (string) $listing->external_id,
                $listing->external_model_id ? (string) $listing->external_model_id : null,
            );
        } catch (Throwable $exception) {
            // Canal fora do ar ou sem credencial: o próximo canal do
            // produto ainda pode ter o conteúdo.
            Log::warning('catalog.product_completion.channel_failed', [
                'product_id' => $product->id,
                'channel' => $listing->channel,
                'message' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param  array{url: string, duration?: ?int}  $video
     */
    private function salvarVideo(Product $product, array $video): bool
    {
        try {
            $resposta = Http::timeout(120)->get($video['url']);
        } catch (Throwable $exception) {
            Log::warning('catalog.product_completion.video_download_failed', ['product_id' => $product->id, 'message' => $exception->getMessage()]);

            return false;
        }

        if (! $resposta->successful() || $resposta->body() === '') {
            return false;
        }

        $extensao = str_contains((string) $resposta->header('Content-Type'), 'quicktime') ? 'mov' : 'mp4';
        $path = "products/{$product->id}/video/".Str::random(24).".{$extensao}";

        Storage::disk('public')->put($path, $resposta->body());

        $product->update([
            'video_path' => $path,
            'video_duration_seconds' => $video['duration'] ?? $product->video_duration_seconds,
        ]);

        return true;
    }
}
