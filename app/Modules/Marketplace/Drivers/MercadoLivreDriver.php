<?php

namespace App\Modules\Marketplace\Drivers;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Services\MercadoLivre\DTOs\ProductDTO;
use App\Services\MercadoLivre\Exceptions\MercadoLivreException;
use App\Services\MercadoLivre\Services\OrderService;
use App\Services\MercadoLivre\Services\ProductService;
use App\Services\MercadoLivre\Services\ShipmentService;

/**
 * Mercado Livre — API docs: https://developers.mercadolivre.com.br
 *
 * Delegates the actual HTTP calls to App\Services\MercadoLivre\Services\ProductService,
 * which uses the OAuth token managed by MercadoLivreAuthService (see
 * app/Services/MercadoLivre). The channel-specific `category_id` (required by
 * the ML API, but meaningless to the other channels) comes from the JSON
 * attributes editor on the product's "Canais de venda" tab.
 */
class MercadoLivreDriver extends AbstractMarketplaceDriver
{
    public function __construct(
        private readonly ProductService $products,
        private readonly OrderService $orders,
        private readonly ShipmentService $shipments,
    ) {}

    public function channel(): string
    {
        return MarketplaceAccount::CHANNEL_MERCADO_LIVRE;
    }

    public function publishProduct(Product $product, ProductChannelListing $listing): string
    {
        $this->ensureConfigured();

        // Already published: update the existing item instead of creating a
        // new one every time staff re-save the channel (confirmed live —
        // re-saving an already-published listing was creating a duplicate
        // item on Mercado Livre each time).
        if ($listing->external_id) {
            $this->products->updateItem($listing->external_id, [
                'price' => (float) $product->final_price,
                'available_quantity' => $product->stock,
            ]);

            return $listing->external_id;
        }

        $categoryId = $listing->attributes['category_id'] ?? '';
        $attributes = $this->buildAttributes($product);
        $pictures = $product->images->map(fn ($image) => ['source' => $image->url])->all();

        $dto = new ProductDTO(
            category_id: $categoryId,
            price: (float) $product->final_price,
            available_quantity: $product->stock,
            title: $product->name,
            description: $product->description,
            pictures: $pictures,
            attributes: $attributes,
        );

        try {
            $response = $this->products->createItem($dto);
        } catch (MercadoLivreException $exception) {
            if (! $this->requiresProductFamily($exception)) {
                throw $exception;
            }

            // Confirmed live: this category rejects a plain title and wants
            // the item grouped under a "product family" instead — ML then
            // derives the title from family_name + attributes itself, which
            // is why title must be omitted (sending both is also rejected).
            $retryDto = new ProductDTO(
                category_id: $categoryId,
                price: (float) $product->final_price,
                available_quantity: $product->stock,
                description: $product->description,
                pictures: $pictures,
                family_name: $product->name,
                attributes: $attributes,
            );

            $response = $this->products->createItem($retryDto);
        }

        return (string) $response['id'];
    }

    public function updateStock(Product $product, ProductChannelListing $listing): void
    {
        $this->ensureConfigured();

        $this->products->updateStock($listing->external_id, $product->stock);
    }

    public function unpublishProduct(ProductChannelListing $listing): void
    {
        $this->ensureConfigured();

        $this->products->closeItem($listing->external_id);
    }

    public function importOrder(string $externalOrderId): array
    {
        $this->ensureConfigured();

        $order = $this->orders->getOrder($externalOrderId);
        $address = $this->resolveShippingAddress($order->shipping);

        $itemsSubtotal = 0.0;
        $items = [];

        foreach ($order->order_items as $item) {
            $externalId = (string) ($item['item']['id'] ?? '');
            $quantity = (int) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['unit_price'] ?? 0);

            if ($externalId === '' || $quantity < 1) {
                continue;
            }

            $itemsSubtotal += $unitPrice * $quantity;
            $items[] = ['external_id' => $externalId, 'quantity' => $quantity, 'unit_price' => $unitPrice];
        }

        $buyer = $order->buyer;
        $buyerName = trim(($buyer['first_name'] ?? '').' '.($buyer['last_name'] ?? '')) ?: ($buyer['nickname'] ?? 'Comprador Mercado Livre');

        return [
            'external_order_id' => (string) $order->id,
            'status' => $this->mapOrderStatus($order->status),
            'subtotal' => round($itemsSubtotal, 2),
            'shipping_cost' => round(max(0, $order->total_amount - $itemsSubtotal), 2),
            'total' => round($order->total_amount, 2),
            'buyer_name' => $buyerName,
            'buyer_phone' => $buyer['phone']['number'] ?? null,
            'shipping_zip' => $address['zip'],
            'shipping_street' => $address['street'],
            'shipping_number' => $address['number'],
            'shipping_complement' => $address['complement'],
            'shipping_neighborhood' => $address['neighborhood'],
            'shipping_city' => $address['city'],
            'shipping_state' => $address['state'],
            'items' => $items,
        ];
    }

    /**
     * ML só devolve o `shipping.id` no payload do pedido — o endereço em si
     * vem de uma chamada separada em /shipments/{id}. Extração toda
     * defensiva (com fallback "Não informado") porque nem todo pedido tem
     * frete gerenciado pelo ML com endereço estruturado (ex: retirada em
     * loja), e um campo faltando não pode derrubar a importação do pedido.
     *
     * @param  array<string, mixed>  $shipping
     * @return array{zip: string, street: string, number: string, complement: ?string, neighborhood: string, city: string, state: string}
     */
    private function resolveShippingAddress(array $shipping): array
    {
        $fallback = [
            'zip' => '00000000',
            'street' => 'Não informado',
            'number' => 'S/N',
            'complement' => null,
            'neighborhood' => 'Não informado',
            'city' => 'Não informado',
            'state' => 'NA',
        ];

        $shipmentId = $shipping['id'] ?? null;

        if (! $shipmentId) {
            return $fallback;
        }

        $receiver = $this->shipments->getShipment((string) $shipmentId)['receiver_address'] ?? [];

        if (empty($receiver)) {
            return $fallback;
        }

        return [
            'zip' => (string) ($receiver['zip_code'] ?? $fallback['zip']),
            'street' => (string) ($receiver['street_name'] ?? $fallback['street']),
            'number' => (string) ($receiver['street_number'] ?? $fallback['number']),
            'complement' => $receiver['comment'] ?? null,
            'neighborhood' => (string) ($receiver['neighborhood']['name'] ?? $fallback['neighborhood']),
            'city' => (string) ($receiver['city']['name'] ?? $fallback['city']),
            'state' => (string) ($receiver['state']['id'] ?? $receiver['state']['name'] ?? $fallback['state']),
        ];
    }

    private function mapOrderStatus(string $status): string
    {
        return match ($status) {
            'paid' => Order::STATUS_PAID,
            'cancelled', 'invalid' => Order::STATUS_CANCELLED,
            default => Order::STATUS_AWAITING_PAYMENT,
        };
    }

    private function requiresProductFamily(MercadoLivreException $exception): bool
    {
        $cause = $exception->context['body']['cause'][0]['message'] ?? '';

        return str_contains($cause, 'family_name');
    }

    /**
     * @return array<int, array{id: string, value_name: string}>
     */
    private function buildAttributes(Product $product): array
    {
        $attributes = [];

        if ($product->brand) {
            $attributes[] = ['id' => 'BRAND', 'value_name' => $product->brand];
        }

        if ($product->model) {
            $attributes[] = ['id' => 'MODEL', 'value_name' => $product->model];
        }

        if ($product->color) {
            // Sent under both IDs: some categories require COLOR, others
            // require MATERIAL (confirmed live — this store's "cor" field is
            // sometimes really describing material, e.g. "Bambu"), and there
            // is no dedicated material field on Product to map separately.
            $attributes[] = ['id' => 'COLOR', 'value_name' => $product->color];
            $attributes[] = ['id' => 'MATERIAL', 'value_name' => $product->color];
        }

        return $attributes;
    }
}
