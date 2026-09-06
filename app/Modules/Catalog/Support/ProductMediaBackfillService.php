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
 * Garante que produto vendido tenha foto, buscando em QUALQUER canal em
 * que ele esteja anunciado.
 *
 * Pedido explícito do usuário em 2026-09-05, nas palavras dele
 * "irrevogável": card de separação nunca pode ficar sem imagem — é pela
 * foto que o operador confere o que embalar.
 *
 * A lacuna que isto fecha: `autoImportProduct()` cria produto a partir da
 * venda em TODOS os drivers sem trazer nenhuma foto, e é justamente esse
 * produto que o OrderImageArchiveService não consegue servir (devolve
 * null -> 404 -> card sem imagem). Até aqui o único caminho automatizado
 * era o ShopeeMediaImportService, acionado só por dois comandos manuais.
 *
 * Não substitui o ShopeeMediaImportService: aquele também traz vídeo e
 * continua sendo o caminho da Shopee. Este cobre o caso geral, canal a
 * canal, e serve de rede pra quem não tem serviço próprio.
 *
 * NUNCA sobrescreve foto existente — só age em produto com zero imagens.
 */
class ProductMediaBackfillService
{
    public function __construct(private readonly MarketplaceDriverManager $drivers) {}

    /**
     * @return int Quantas fotos foram efetivamente salvas.
     */
    public function fill(Product $product): int
    {
        if ($product->images()->exists()) {
            return 0;
        }

        $listings = ProductChannelListing::query()
            ->where('product_id', $product->id)
            ->whereNotNull('external_id')
            ->get();

        foreach ($listings as $listing) {
            $urls = $this->urlsDoCanal($product, $listing);

            if ($urls === []) {
                continue;
            }

            $salvas = $this->salvar($product, $urls);

            // Primeiro canal que devolveu foto encerra a busca: as fotos
            // são do mesmo produto, misturar canais só geraria duplicata.
            if ($salvas > 0) {
                Log::info('catalog.media_backfill.filled', [
                    'product_id' => $product->id,
                    'channel' => $listing->channel,
                    'images' => $salvas,
                ]);

                return $salvas;
            }
        }

        return 0;
    }

    /**
     * @return array<int, string>
     */
    private function urlsDoCanal(Product $product, ProductChannelListing $listing): array
    {
        try {
            return $this->drivers->driver($listing->channel)->fetchItemImages(
                (string) $listing->external_id,
                $listing->external_model_id ? (string) $listing->external_model_id : null,
            );
        } catch (Throwable $exception) {
            // Canal fora do ar, sem credencial ou sem suporte a mídia não
            // pode derrubar a varredura — o próximo canal do produto ainda
            // pode ter a foto.
            Log::warning('catalog.media_backfill.channel_failed', [
                'product_id' => $product->id,
                'channel' => $listing->channel,
                'message' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param  array<int, string>  $urls
     */
    private function salvar(Product $product, array $urls): int
    {
        $salvas = 0;

        foreach (array_values($urls) as $posicao => $url) {
            $path = $this->baixar("products/{$product->id}", $url);

            if (! $path) {
                continue;
            }

            // Mesmo tratamento das fotos enviadas pelo admin: 1600px, JPEG.
            // Sem isso a foto do canal entraria em tamanho original e o
            // card do KoraSync baixaria megabytes por item.
            $path = ProductImageOptimizer::optimize($path) ?? $path;

            $product->images()->create([
                'path' => $path,
                'position' => $posicao,
                'is_primary' => $posicao === 0,
            ]);

            $salvas++;
        }

        return $salvas;
    }

    private function baixar(string $diretorio, string $url): ?string
    {
        try {
            $resposta = Http::timeout(45)->get($url);
        } catch (Throwable $exception) {
            Log::warning('catalog.media_backfill.download_failed', [
                'url' => $url,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        if (! $resposta->successful() || $resposta->body() === '') {
            return null;
        }

        $path = "{$diretorio}/".Str::random(24).'.'.$this->extensao((string) $resposta->header('Content-Type'), $url);

        Storage::disk('public')->put($path, $resposta->body());

        return $path;
    }

    private function extensao(string $contentType, string $url): string
    {
        return match (true) {
            str_contains($contentType, 'png') => 'png',
            str_contains($contentType, 'webp') => 'webp',
            str_contains($contentType, 'gif') => 'gif',
            str_contains($contentType, 'jpeg'), str_contains($contentType, 'jpg') => 'jpg',
            default => pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION) ?: 'jpg',
        };
    }
}
