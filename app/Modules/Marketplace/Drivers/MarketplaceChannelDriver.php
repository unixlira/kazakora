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
     * Tenta criar um produto local do zero a partir de um item ainda não
     * vinculado (anúncio feito direto no canal, fora do Kazakora) — chamado
     * por OrderImportService quando um pedido chega com um item sem
     * `product_channel_listings` correspondente, pra não deixar o pedido
     * inteiro sem NF-e/etiqueta só porque o catálogo local nunca ficou
     * sabendo desse anúncio. Entra sempre como rascunho — dados fiscais só
     * quando o canal já tiver isso preenchido de verdade (nunca inventado).
     * Canais sem essa capacidade (ainda) implementada devolvem null — mesmo
     * comportamento de "sem produto vinculado" que já existia antes, não é
     * regressão.
     *
     * $quantitySold: quantidade desta própria venda, que OrderImportService
     * ainda vai debitar do estoque logo em seguida (fluxo normal, igual pra
     * qualquer produto). Se o driver conseguir puxar o estoque "atual" real
     * do canal, esse número já reflete esta venda — soma $quantitySold de
     * volta antes de gravar, pra a baixa seguinte não contar a mesma venda
     * 2x. Ignorado por canais que não puxam estoque no auto-import.
     *
     * $externalModelId: variação específica dentro do anúncio (ver
     * importOrder(), items[].external_model_id) — quando presente, o
     * listing criado pra este produto novo já nasce vinculado à variação
     * certa, não ao anúncio inteiro. Null pra canais/itens sem variação.
     */
    public function autoImportProduct(string $externalId, int $quantitySold = 0, ?string $externalModelId = null): ?Product;

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

    /**
     * Busca as avaliações (nota, comentário, nome do comprador e imagens
     * anexadas, quando o canal devolver) de um anúncio já publicado —
     * usado por ReviewImportService, que roda pra TODOS os canais
     * conectados sem precisar saber o formato de cada API. Pura consulta,
     * nunca escreve nada no canal.
     *
     * Canais sem essa capacidade ainda implementada lançam
     * RuntimeException, mesmo padrão de publishProduct()/importOrder() —
     * ver AbstractMarketplaceDriver::fetchReviews() pro default. Quem
     * chama já sabe pular esse canal e seguir pro próximo.
     *
     * @return array<int, array{
     *     external_id: string,
     *     rating: int,
     *     comment: ?string,
     *     reviewer_name: string,
     *     images: array<int, string>,
     *     created_at: ?\Illuminate\Support\Carbon,
     * }>
     */
    public function fetchReviews(string $externalId): array;
}
