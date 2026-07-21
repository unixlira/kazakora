<?php

namespace App\Modules\Marketplace\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Jobs\PublishProductToChannel;
use App\Modules\Marketplace\Models\ProductChannelListing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductChannelController extends Controller
{
    public function __construct(private readonly MarketplaceDriverManager $drivers)
    {
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

        $listing = $product->channelListings()->updateOrCreate(
            ['channel' => $channel],
            [
                'is_enabled' => $validated['is_enabled'],
                'attributes' => $validated['attributes'] ?? [],
            ],
        );

        if ($listing->is_enabled) {
            if (! $this->drivers->driver($channel)->isConfigured()) {
                $listing->update(['is_enabled' => false]);

                return back()->with('warning', "A conta do canal \"{$channel}\" ainda não foi conectada. Peça pro time configurar as credenciais de API antes de publicar.");
            }

            $listing->update(['status' => ProductChannelListing::STATUS_PENDING]);
            PublishProductToChannel::dispatch($product, $listing);

            return back()->with('success', 'Publicação enviada para a fila. Isso pode levar alguns minutos.');
        }

        return back()->with('success', 'Canal atualizado.');
    }
}
