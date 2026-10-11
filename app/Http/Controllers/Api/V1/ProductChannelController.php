<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductChannelListingResource;
use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Jobs\PublishProductToChannel;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Services\MercadoLivre\Services\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * API pública, pedido explícito 2026-08-22 — publicar/sincronizar/encerrar
 * anúncio em canal de marketplace (Mercado Livre, Shopee, ...) direto pela
 * API, fechando a lacuna deixada pela rodada anterior (ver commit da API
 * pública). Mesma lógica/drivers do painel admin (ver
 * App\Modules\Marketplace\Http\Controllers\ProductChannelController), só
 * respondendo JSON em vez de redirect+flash. Ability reaproveitada:
 * `cadastros.edit` — publicar num canal é uma edição da exposição do
 * produto, mesmo vocabulário já usado pelo endpoint de update() de
 * produto.
 */
class ProductChannelController extends Controller
{
    public function __construct(
        private readonly MarketplaceDriverManager $drivers,
        private readonly CategoryService $categories,
    ) {}

    public function index(Product $product): AnonymousResourceCollection
    {
        return ProductChannelListingResource::collection($product->channelListings);
    }

    public function update(Request $request, Product $product, string $channel): JsonResponse
    {
        $validated = $request->validate([
            'is_enabled' => ['required', 'boolean'],
            'attributes' => ['nullable', 'array'],
        ]);

        $this->assertValidChannel($channel);

        $attributes = $validated['attributes'] ?? [];

        if ($channel === 'mercado_livre' && $validated['is_enabled'] && empty($attributes['category_id'])) {
            $attributes = array_merge($attributes, $this->discoverMercadoLivreCategory($product));
        }

        $listing = $product->channelListings()->updateOrCreate(
            ['channel' => $channel],
            [
                'is_enabled' => $validated['is_enabled'],
                'attributes' => $attributes,
            ],
        );

        if (! $listing->is_enabled) {
            return (new ProductChannelListingResource($listing))->response();
        }

        if (! $this->drivers->driver($channel)->isConfigured()) {
            $listing->update(['is_enabled' => false]);

            return response()->json([
                'message' => "A conta do canal \"{$channel}\" ainda não foi conectada. Peça pro time configurar as credenciais de API antes de publicar.",
                'listing' => new ProductChannelListingResource($listing),
            ], 422);
        }

        if ($channel === 'mercado_livre' && empty($listing->attributes['category_id'])) {
            $listing->update(['is_enabled' => false]);

            return response()->json([
                'message' => 'Não foi possível identificar automaticamente a categoria do Mercado Livre para este produto. Informe "attributes.category_id" no payload e tente de novo.',
                'listing' => new ProductChannelListingResource($listing),
            ], 422);
        }

        $listing->update(['status' => ProductChannelListing::STATUS_PENDING]);

        try {
            // Mesmo motivo do painel admin: QUEUE_CONNECTION roda em modo
            // sync nesta hospedagem (sem worker persistente), então o job
            // executa aqui mesmo e seu rethrow (ver PublishProductToChannel)
            // precisa virar resposta JSON normal em vez de 500 cru.
            PublishProductToChannel::dispatch($product, $listing);
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'Não foi possível publicar agora: '.$exception->getMessage(),
                'listing' => new ProductChannelListingResource($listing->fresh()),
            ], 422);
        }

        return (new ProductChannelListingResource($listing->fresh()))->response();
    }

    public function sync(Product $product, string $channel): JsonResponse
    {
        $this->assertValidChannel($channel);

        $listing = $product->channelListings()->where('channel', $channel)->first();

        if (! $listing || ! $listing->external_id) {
            return response()->json([
                'message' => 'Este produto ainda não foi publicado neste canal — não há o que sincronizar.',
            ], 422);
        }

        $listing->update(['status' => ProductChannelListing::STATUS_PENDING]);

        try {
            PublishProductToChannel::dispatch($product, $listing);
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'Não foi possível sincronizar agora: '.$exception->getMessage(),
                'listing' => new ProductChannelListingResource($listing->fresh()),
            ], 422);
        }

        return (new ProductChannelListingResource($listing->fresh()))->response();
    }

    public function destroy(Product $product, string $channel): JsonResponse
    {
        $this->assertValidChannel($channel);

        $listing = $product->channelListings()->where('channel', $channel)->first();

        if (! $listing || ! $listing->external_id) {
            return response()->json([
                'message' => 'Este produto não está publicado neste canal.',
            ], 422);
        }

        try {
            // Confirmado pro Mercado Livre: não existe DELETE de verdade num
            // anúncio que já foi ao ar — o driver encerra/pausa em vez de
            // apagar (ver unpublishProduct() do driver de cada canal).
            $this->drivers->driver($channel)->unpublishProduct($listing);
        } catch (\Throwable $exception) {
            return response()->json([
                'message' => 'Não foi possível remover o anúncio da plataforma: '.$exception->getMessage(),
            ], 422);
        }

        $listing->update(['is_enabled' => false, 'status' => ProductChannelListing::STATUS_DRAFT]);

        return (new ProductChannelListingResource($listing->fresh()))->response();
    }

    private function assertValidChannel(string $channel): void
    {
        if (! in_array($channel, $this->drivers->channels(), true)) {
            throw ValidationException::withMessages([
                'channel' => ["Canal desconhecido: {$channel}."],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function discoverMercadoLivreCategory(Product $product): array
    {
        try {
            $matches = $this->categories->discoverCategory($product->name);
        } catch (\Throwable) {
            return [];
        }

        if (empty($matches[0]['category_id'])) {
            return [];
        }

        return ['category_id' => $matches[0]['category_id']];
    }
}
