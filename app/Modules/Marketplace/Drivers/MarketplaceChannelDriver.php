<?php

namespace App\Modules\Marketplace\Drivers;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Models\ProductChannelListing;

interface MarketplaceChannelDriver
{
    public function channel(): string;

    public function isConfigured(): bool;

    /**
     * Create or update the product listing on the marketplace, including
     * images, and return the marketplace's external listing id.
     */
    public function publishProduct(Product $product, ProductChannelListing $listing): string;

    public function updateStock(Product $product, ProductChannelListing $listing): void;

    /**
     * Take the listing down from the marketplace. Most marketplaces don't
     * support a true delete once a listing has ever gone live (order/review
     * history has to be preserved) — this closes/deactivates it instead.
     */
    public function unpublishProduct(ProductChannelListing $listing): void;

    /**
     * Fetch an order placed on the channel and normalize it to a common
     * shape, so OrderImportService never has to know which channel it came
     * from. `status` must already be translated to one of Order::STATUS_*
     * — each driver owns the mapping from its own native vocabulary.
     * `items[].external_id` must match a `product_channel_listings.external_id`
     * for this channel, so the import can resolve it back to a local Product.
     *
     * @return array{
     *     external_order_id: string,
     *     status: string,
     *     subtotal: float,
     *     shipping_cost: float,
     *     total: float,
     *     buyer_name: string,
     *     buyer_phone: ?string,
     *     buyer_email?: ?string,
     *     buyer_whatsapp?: ?string,
     *     shipping_zip: string,
     *     shipping_street: string,
     *     shipping_number: string,
     *     shipping_complement: ?string,
     *     shipping_neighborhood: string,
     *     shipping_city: string,
     *     shipping_state: string,
     *     external_shipment_id: ?string,
     *     items: array<int, array{external_id: string, quantity: int, unit_price: float}>,
     * }
     */
    public function importOrder(string $externalOrderId): array;

    /**
     * Envia a NF-e autorizada de um pedido pro canal (obrigatório antes do
     * canal liberar o envio, na maioria dos casos). O driver decide o
     * formato exigido pelo canal (XML, PDF do DANFE, ou ambos) e lê o
     * arquivo direto do Storage a partir de `$invoice->xml_path`/`danfe_path`.
     *
     * @return array{status: string, external_reference: ?string, response: array<string, mixed>}
     */
    public function submitInvoice(Order $order, Invoice $invoice): array;

    /**
     * Garante que o método de envio do pedido está confirmado no canal e
     * devolve qual foi. Em canais onde o método é decidido automaticamente
     * pelo próprio canal (ex: Mercado Envios Flex x padrão, decidido pelo ML
     * com base em CEP/cobertura), este método só CONSULTA a decisão já
     * tomada — não escolhe nada aqui. Em canais onde uma confirmação
     * explícita é necessária (ex: Shopee drop-off), este método faz essa
     * chamada.
     *
     * @return array{external_shipment_id: ?string, shipping_method: string, status: string}
     */
    public function confirmShipping(Order $order): array;

    /**
     * Verifica se a etiqueta do pedido já está pronta e, se estiver, baixa o
     * conteúdo. `ready: false` significa "ainda processando, tente de novo
     * depois" — não é um erro.
     *
     * @return array{ready: bool, contents: ?string, content_type: ?string}
     */
    public function fetchLabel(Order $order): array;
}
