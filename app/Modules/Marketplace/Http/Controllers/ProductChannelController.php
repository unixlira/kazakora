<?php

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Jobs\PublishProductToChannel;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Services\MercadoLivre\Services\CategoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductChannelController extends Controller
{
    public function __construct(
        private readonly MarketplaceDriverManager $drivers,
        private readonly CategoryService $categories,
    ) {
    }

    public function update(Request $request, Product $product, string $channel): RedirectResponse
    {
        $validated = $request->validate([
            'is_enabled' => ['required', 'boolean'],
            'attributes' => ['nullable', 'array'],
        ]);

        if (! in_array($channel, $this->drivers->channels(), true)) {
            abort(404);
        }

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

        if ($listing->is_enabled) {
            if (! $this->drivers->driver($channel)->isConfigured()) {
                $listing->update(['is_enabled' => false]);

                return back()->with('warning', "A conta do canal \"{$channel}\" ainda não foi conectada. Peça pro time configurar as credenciais de API antes de publicar.");
            }

            if ($channel === 'mercado_livre' && empty($listing->attributes['category_id'])) {
                $listing->update(['is_enabled' => false]);

                return back()->with('warning', 'Não foi possível identificar automaticamente a categoria do Mercado Livre para este produto. Informe o "category_id" manualmente no JSON de atributos e salve de novo.');
            }

            $listing->update(['status' => ProductChannelListing::STATUS_PENDING]);

            try {
                // The queue runs in "sync" mode in this environment (no
                // persistent worker on shared hosting), so the job executes
                // inline here and its rethrow (see PublishProductToChannel)
                // would otherwise bubble up as an unhandled 500 — the job
                // already recorded status=error/last_error on $listing
                // before rethrowing, so this catch just turns that into a
                // normal redirect instead of a crash page.
                PublishProductToChannel::dispatch($product, $listing);
            } catch (\Throwable $exception) {
                return back()->with('warning', 'Não foi possível publicar agora: '.$exception->getMessage());
            }

            return back()->with('success', 'Publicação enviada para a fila. Isso pode levar alguns minutos.');
        }

        return back()->with('success', 'Canal atualizado.');
    }

    public function sync(Product $product, string $channel): RedirectResponse
    {
        if (! in_array($channel, $this->drivers->channels(), true)) {
            abort(404);
        }

        $listing = $product->channelListings()->where('channel', $channel)->first();

        if (! $listing || ! $listing->external_id) {
            return back()->with('warning', 'Este produto ainda não foi publicado neste canal — não há o que sincronizar.');
        }

        $listing->update(['status' => ProductChannelListing::STATUS_PENDING]);

        try {
            PublishProductToChannel::dispatch($product, $listing);
        } catch (\Throwable $exception) {
            return back()->with('warning', 'Não foi possível sincronizar agora: '.$exception->getMessage());
        }

        return back()->with('success', 'Anúncio sincronizado com sucesso.');
    }

    public function destroy(Product $product, string $channel): RedirectResponse
    {
        if (! in_array($channel, $this->drivers->channels(), true)) {
            abort(404);
        }

        $listing = $product->channelListings()->where('channel', $channel)->first();

        if (! $listing || ! $listing->external_id) {
            return back()->with('warning', 'Este produto não está publicado neste canal.');
        }

        try {
            // Most marketplaces (confirmed for Mercado Livre — DELETE isn't
            // supported once an item has ever gone live) close/deactivate
            // the listing rather than truly deleting it.
            $this->drivers->driver($channel)->unpublishProduct($listing);
        } catch (\Throwable $exception) {
            return back()->with('warning', 'Não foi possível remover o anúncio da plataforma: '.$exception->getMessage());
        }

        $listing->update(['is_enabled' => false, 'status' => ProductChannelListing::STATUS_DRAFT]);

        return back()->with('success', 'Anúncio encerrado na plataforma.');
    }

    /**
     * Best-effort: ask Mercado Livre to identify the category for this
     * product's name so staff don't have to look up/type the category_id by
     * hand. Falls back to an empty array on any failure (e.g. ML API down,
     * not connected) — the caller then asks for it manually instead of
     * blocking the save.
     *
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
