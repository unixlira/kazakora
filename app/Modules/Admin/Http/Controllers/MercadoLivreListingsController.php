<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Listagem de anúncios publicados/pendentes no Mercado Livre — mesmos dados
 * já usados na aba "Canais de venda" de cada produto individualmente
 * (ProductChannelController), só que agrupados numa tela só em vez de
 * precisar abrir produto por produto. Pedido do usuário 2026-08-05.
 */
class MercadoLivreListingsController extends Controller
{
    public function index(): Response
    {
        $listings = ProductChannelListing::query()
            ->where('channel', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)
            ->with('product:id,name,sku,price,stock')
            ->latest('last_synced_at')
            ->get()
            ->map(fn (ProductChannelListing $listing) => [
                'id' => $listing->id,
                'productId' => $listing->product_id,
                'productName' => $listing->product?->name ?? '(produto removido)',
                'sku' => $listing->product?->sku,
                'externalId' => $listing->external_id,
                'externalUrl' => $listing->external_id ? "https://produto.mercadolivre.com.br/{$listing->external_id}" : null,
                'status' => $listing->status,
                'isEnabled' => $listing->is_enabled,
                'price' => $listing->product?->price,
                'stock' => $listing->product?->stock,
                'lastSyncedAt' => $listing->last_synced_at,
                'lastError' => $listing->last_error,
            ]);

        return Inertia::render('Admin/Integracoes/MercadoLivre/Anuncios', [
            'listings' => $listings,
        ]);
    }
}
