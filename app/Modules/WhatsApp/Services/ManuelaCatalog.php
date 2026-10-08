<?php

namespace App\Modules\WhatsApp\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\WhatsApp\Support\WhatsAppSettings;
use Illuminate\Support\Facades\Cache;

/**
 * Os produtos da loja que a Manuela pode oferecer, em texto pro prompt.
 * Sem isso a IA inventa (teste real 2026-10-08: prometeu "mimos especiais
 * nas compras" que a loja não dá). Cache curto: preço e estoque mudam.
 */
class ManuelaCatalog
{
    private const LIMIT = 150;

    public function __construct(private readonly WhatsAppSettings $settings) {}

    public function asText(): string
    {
        return Cache::remember('whatsapp:manuela:catalogo', now()->addMinutes(10), function () {
            $base = rtrim((string) $this->settings->get('store_base_url'), '/');

            $lines = Product::query()
                ->with('parent:id,slug')
                ->where('is_active', true)
                ->where('stock', '>', 0)
                ->orderByDesc('is_featured')
                ->orderBy('name')
                ->limit(self::LIMIT)
                ->get()
                ->map(fn (Product $product) => sprintf(
                    '- %s | R$ %s | %s/produtos/%s',
                    $product->name,
                    number_format((float) $product->final_price, 2, ',', '.'),
                    $base,
                    $product->parent?->slug ?? $product->slug,
                ));

            return $lines->isEmpty() ? '(nenhum produto com estoque agora)' : $lines->implode("\n");
        });
    }
}
