<?php

namespace App\Modules\Marketplace\Drivers;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Services\MercadoLivre\DTOs\ProductDTO;
use App\Services\MercadoLivre\Exceptions\MercadoLivreException;
use App\Services\MercadoLivre\MercadoLivreClient;
use App\Services\MercadoLivre\Services\OrderService;
use App\Services\MercadoLivre\Services\ProductService;
use App\Services\MercadoLivre\Services\ShipmentService;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

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
        private readonly MercadoLivreClient $client,
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
            'external_shipment_id' => isset($order->shipping['id']) ? (string) $order->shipping['id'] : null,
            'items' => $items,
        ];
    }

    /**
     * Envia o XML da NF-e assinada pro Mercado Livre. Endpoint documentado
     * pra Flex/Turbo/ME1/Drop Off — a doc em inglês desse mesmo endpoint diz
     * "não disponível pro Brasil" enquanto a doc em português o documenta
     * especificamente pra vendedores brasileiros; **não confirmado ao vivo**
     * (bloqueado pelo certificado NF-e sem senha correta — nenhuma nota real
     * foi autorizada ainda pra testar isso de ponta a ponta). Usa
     * external_order_id como pack_id, conforme a própria doc do ML orienta
     * quando o pack_id não está disponível separadamente.
     */
    public function submitInvoice(Order $order, Invoice $invoice): array
    {
        $this->ensureConfigured();

        if (! $invoice->xml_path) {
            throw new RuntimeException('Nota fiscal sem XML disponível para envio.');
        }

        $xml = Storage::disk('local')->get($invoice->xml_path);
        $filename = "nfe-{$invoice->chave_acesso}.xml";

        try {
            $response = $this->client->postMultipart(
                "packs/{$order->external_order_id}/fiscal_documents",
                [],
                ['contents' => $xml, 'filename' => $filename],
                'fiscal_document',
            );

            return [
                'status' => 'sent',
                'external_reference' => isset($response['id']) ? (string) $response['id'] : null,
                'response' => $response,
            ];
        } catch (MercadoLivreException $exception) {
            return [
                'status' => 'error',
                'external_reference' => null,
                'response' => ['error' => $exception->getMessage(), 'context' => $exception->context],
            ];
        }
    }

    /**
     * Flex x envio padrão é decidido automaticamente pelo Mercado Livre (item
     * com Flex ativo + categoria elegível + CEP do comprador dentro da área
     * de cobertura do vendedor) — este método só CONSULTA a decisão já
     * tomada via /shipments/{id}, nunca escolhe nada. Campo real confirmado
     * ao vivo (2026-08-01, pedido 2000017253083882): `logistic_type` e
     * `mode` são campos de topo no shipment, não aninhados sob `logistic.*`
     * como a documentação do ML sugere.
     */
    public function confirmShipping(Order $order): array
    {
        $this->ensureConfigured();

        $shipmentId = $this->resolveShipmentId($order);
        $shipment = $this->shipments->getShipment($shipmentId);

        return [
            'external_shipment_id' => (string) ($shipment['id'] ?? $shipmentId),
            'shipping_method' => $shipment['logistic_type'] ?? 'unknown',
            'status' => $shipment['status'] ?? 'unknown',
        ];
    }

    /**
     * Etiqueta só fica disponível com status=ready_to_ship e
     * substatus=ready_to_print — fora disso, `ready: false` (não é erro, só
     * ainda não chegou lá). Sem webhook dedicado a "etiqueta pronta": o
     * chamador precisa reconsultar (ver comando agendado de polling).
     */
    public function fetchLabel(Order $order): array
    {
        $this->ensureConfigured();

        $shipmentId = $this->resolveShipmentId($order);
        $shipment = $this->shipments->getShipment($shipmentId);

        if (($shipment['status'] ?? null) !== 'ready_to_ship' || ($shipment['substatus'] ?? null) !== 'ready_to_print') {
            return ['ready' => false, 'contents' => null, 'content_type' => null];
        }

        $label = $this->client->getBinary('shipment_labels', [
            'shipment_ids' => $shipmentId,
            'response_type' => 'pdf',
        ]);

        return ['ready' => true, 'contents' => $label['contents'], 'content_type' => $label['content_type']];
    }

    private function resolveShipmentId(Order $order): string
    {
        $shipmentId = ChannelShipment::query()
            ->where('order_id', $order->id)
            ->where('channel', $this->channel())
            ->value('external_shipment_id');

        if (! $shipmentId) {
            throw new RuntimeException("Pedido #{$order->id} não tem shipment_id do Mercado Livre registrado.");
        }

        return $shipmentId;
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
            // orders.shipping_state é varchar(2) — o state.id do ML vem
            // como "BR-SP" (prefixo de país + UF), então só a UF interessa.
            'state' => strtoupper(substr((string) ($receiver['state']['id'] ?? ''), -2)) ?: $fallback['state'],
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
