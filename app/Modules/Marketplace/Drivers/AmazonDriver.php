<?php

namespace App\Modules\Marketplace\Drivers;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Company;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Services\Amazon\AmazonClient;
use App\Services\Amazon\Exceptions\AmazonException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Amazon (Selling Partner API / SP-API) — docs:
 * https://developer-docs.amazon.com/sp-api
 *
 * Autenticação: LWA via App\Services\Amazon\AmazonAuthService/AmazonClient
 * (ver comentário lá pra assinatura/RDT). Pedido importado via Orders API
 * (real, confirmado contra o schema oficial). Envio+etiqueta via Merchant
 * Fulfillment API (MFN) — a Amazon devolve a etiqueta já pronta na mesma
 * chamada que compra o frete (síncrono, diferente do Mercado Livre/Shopee
 * que processam a etiqueta de forma assíncrona), por isso confirmShipping()
 * já resolve os dois passos e fetchLabel() só relê o que já foi baixado.
 *
 * submitInvoice() e publishProduct/updateStock/unpublishProduct ficam como
 * stub. Os dois últimos por estarem fora do escopo pedido (webhook de venda
 * → nota fiscal → envio → etiqueta) e o payload real depender do
 * `productType` de cada categoria (Listings Items API / Product Type
 * Definitions API), sem como confirmar o schema exato sem uma chamada real.
 * `submitInvoice()` por um motivo diferente: **não existe, na documentação
 * pública, um endpoint SP-API pra um vendedor MFN brasileiro enviar NF-e** —
 * ver o comentário do método pra o que foi de fato checado (Feeds API,
 * Invoices API, Shipment Invoicing API) antes de concluir isso.
 */
class AmazonDriver extends AbstractMarketplaceDriver
{
    private const LISTINGS_API_VERSION = '2021-08-01';

    public function __construct(private readonly AmazonClient $client) {}

    public function channel(): string
    {
        return MarketplaceAccount::CHANNEL_AMAZON;
    }

    public function publishProduct(Product $product, ProductChannelListing $listing): string
    {
        $this->ensureConfigured();

        // TODO: PUT /listings/2021-08-01/items/{sellerId}/{sku} exige
        // `productType` (Product Type Definitions API,
        // searchDefinitionsProductTypes por palavra-chave) + um payload de
        // `attributes` cujo formato varia por productType — sem uma conta
        // real conectada pra iterar contra os erros de validação (mesmo
        // caminho que MercadoLivreDriver::publishProduct() precisou pra
        // descobrir `family_name`/atributos na prática), não dá pra cravar
        // o schema aqui sem risco real de anúncio malformado.
        throw new \RuntimeException('Integração de publicação de produto com a Amazon ainda não implementada.');
    }

    public function updateStock(Product $product, ProductChannelListing $listing): void
    {
        $this->ensureConfigured();

        // TODO: PATCH no mesmo endpoint acima, op=replace no atributo
        // `fulfillment_availability` — mesmo bloqueio de schema do método
        // acima.
        throw new \RuntimeException('Integração de publicação de produto com a Amazon ainda não implementada.');
    }

    public function unpublishProduct(ProductChannelListing $listing): void
    {
        $this->ensureConfigured();

        // TODO: DELETE /listings/2021-08-01/items/{sellerId}/{sku}.
        throw new \RuntimeException('Integração de publicação de produto com a Amazon ainda não implementada.');
    }

    /**
     * Orders API v0. `getOrder` devolve status/total/data — endereço e
     * documento (CPF/CNPJ) do comprador exigem um Restricted Data Token
     * dedicado (ver AmazonClient::restrictedGet()), a Amazon mascara esses
     * campos por padrão em qualquer chamada sem RDT.
     */
    public function importOrder(string $externalOrderId): array
    {
        $this->ensureConfigured();

        $order = $this->client->get("/orders/v0/orders/{$externalOrderId}")['payload'] ?? null;

        if (! $order) {
            throw new RuntimeException("Pedido {$externalOrderId} não encontrado na Amazon.");
        }

        $itemsResponse = $this->client->get("/orders/v0/orders/{$externalOrderId}/orderItems");
        $itemsSubtotal = 0.0;
        $items = [];

        foreach ($itemsResponse['payload']['OrderItems'] ?? [] as $item) {
            $sku = (string) ($item['SellerSKU'] ?? '');
            $quantity = (int) ($item['QuantityOrdered'] ?? 0);
            $unitPrice = isset($item['ItemPrice']['Amount']) && $quantity > 0
                ? round((float) $item['ItemPrice']['Amount'] / $quantity, 2)
                : 0.0;

            if ($sku === '' || $quantity < 1) {
                continue;
            }

            $itemsSubtotal += $unitPrice * $quantity;

            $items[] = [
                'external_id' => $sku,
                'external_name' => $item['Title'] ?? null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
            ];
        }

        $address = $this->fetchAddress($externalOrderId);
        $buyer = $this->fetchBuyerInfo($externalOrderId);

        $total = (float) ($order['OrderTotal']['Amount'] ?? $itemsSubtotal);
        $shippingCost = round(max(0, $total - $itemsSubtotal), 2);

        return [
            'external_order_id' => (string) ($order['AmazonOrderId'] ?? $externalOrderId),
            'status' => $this->mapOrderStatus((string) ($order['OrderStatus'] ?? '')),
            'subtotal' => round($itemsSubtotal, 2),
            'shipping_cost' => $shippingCost,
            'total' => round($total, 2),
            'buyer_name' => $buyer['name'] ?? $address['name'] ?? 'Comprador Amazon',
            'buyer_document' => $buyer['document'] ?? null,
            'buyer_email' => $buyer['email'] ?? null,
            'buyer_phone' => $address['phone'] ?? null,
            'shipping_zip' => $address['zip'] ?? '00000000',
            'shipping_street' => $address['street'] ?? 'Não informado',
            'shipping_number' => 'S/N',
            'shipping_complement' => $address['complement'] ?? null,
            'shipping_neighborhood' => 'Não informado',
            'shipping_city' => $address['city'] ?? 'Não informado',
            'shipping_state' => $address['state'] ?? 'NA',
            'external_shipment_id' => null,
            // ->setTimezone(): PurchaseDate vem sempre em UTC ("...Z", SP-API
            // nunca manda outro offset) — 3h à FRENTE de São Paulo
            // (-03:00). Carbon::parse() de uma string com "Z" fixa o objeto
            // em UTC e não converte sozinho pro timezone padrão do PHP; sem
            // ->setTimezone() aqui, OrderImportService::createOrder()
            // grava os dígitos de UTC direto como se já fossem hora de SP
            // (Eloquent formata no timezone que o Carbon já tem) —
            // created_at fica 3h ADIANTADO da hora real de SP. Mesma classe
            // de bug do MercadoLivreDriver (ver comentário lá), só que na
            // direção oposta: aqui um pedido feito ontem à noite (hora de
            // SP) pode virar "hoje de madrugada" no banco e vazar pra fila
            // "só hoje" do KoraSync (DashboardAgentController::queue()).
            'placed_at' => isset($order['PurchaseDate']) ? \Illuminate\Support\Carbon::parse($order['PurchaseDate'])->setTimezone(config('app.timezone')) : null,
            'items' => $items,
        ];
    }

    /**
     * Vocabulário real de `OrderStatus`: PendingAvailability, Pending,
     * Unshipped, PartiallyShipped, Shipped, Canceled, Unfulfillable. A
     * Amazon só libera o pedido pro vendedor depois do pagamento
     * confirmado — "Unshipped"/"PartiallyShipped" já significam pago e
     * prontos pra expedir, não "aguardando pagamento" como em outros
     * canais.
     */
    private function mapOrderStatus(string $status): string
    {
        return match ($status) {
            'Unshipped', 'PartiallyShipped' => Order::STATUS_PAID,
            'Shipped' => Order::STATUS_SHIPPED,
            'Canceled', 'Unfulfillable' => Order::STATUS_CANCELLED,
            default => Order::STATUS_AWAITING_PAYMENT,
        };
    }

    /**
     * `getOrderAddress`, dado pessoal — exige RDT com dataElements
     * `shippingAddress`. Nunca lança: um pedido sem endereço liberado (ex:
     * retirada/loja física, ou janela de acesso a PII já expirada) não pode
     * travar a importação, só cai nos fallbacks "Não informado".
     *
     * @return array{name: ?string, zip: ?string, street: ?string, complement: ?string, city: ?string, state: ?string, phone: ?string}
     */
    private function fetchAddress(string $orderId): array
    {
        try {
            $response = $this->client->restrictedGet("/orders/v0/orders/{$orderId}/address", ['shippingAddress']);
            $address = $response['payload']['ShippingAddress'] ?? [];
        } catch (AmazonException) {
            return [];
        }

        if (empty($address)) {
            return [];
        }

        $street = trim(($address['AddressLine1'] ?? '').(isset($address['AddressLine2']) ? ' '.$address['AddressLine2'] : ''));

        return [
            'name' => $address['Name'] ?? null,
            'zip' => isset($address['PostalCode']) ? preg_replace('/\D/', '', (string) $address['PostalCode']) : null,
            'street' => $street ?: null,
            'complement' => $address['AddressLine3'] ?? null,
            'city' => $address['City'] ?? null,
            // shipping_state na tabela orders é varchar(2) — mesmo gotcha já
            // corrigido pro Mercado Livre/Shopee (ver histórico dos
            // respectivos drivers). Não confirmado ao vivo se StateOrRegion
            // da Amazon Brasil já vem só a UF ou o nome completo do estado
            // — normaliza defensivamente pros dois casos.
            'state' => $this->extractState((string) ($address['StateOrRegion'] ?? '')),
            'phone' => $address['Phone'] ?? null,
        ];
    }

    /**
     * `getOrderBuyerInfo`, dado pessoal — exige RDT com dataElements
     * `buyerInfo` (confirmado contra o schema oficial da Orders API: o
     * dataElement `buyerInfo` já inclui `BuyerTaxInfo` — não existe/não é
     * preciso um dataElement separado pra isso; `buyerTaxInformation` é
     * outro campo, específico da Turquia, não relacionado). `TaxClassifications`
     * é onde a Amazon Brasil expõe CPF/CNPJ do comprador (obrigatório pra
     * emissão de NF-e) — mesmo princípio do `buyer_cpf_id` que o driver da
     * Shopee já precisou descobrir na prática. **Ressalva real encontrada
     * na doc**: `BuyerTaxInfo` só é preenchido "for business orders in the
     * Brazil, Mexico and India marketplaces" — não confirmado se isso
     * cobre toda venda B2C brasileira (onde CPF é sempre obrigatório por
     * lei) ou só compras feitas via Amazon Business; se `document` vier
     * sempre null na prática, essa é a explicação mais provável, não um
     * bug de código.
     *
     * @return array{name: ?string, email: ?string, document: ?string}
     */
    private function fetchBuyerInfo(string $orderId): array
    {
        try {
            $response = $this->client->restrictedGet("/orders/v0/orders/{$orderId}/buyerInfo", ['buyerInfo']);
            $payload = $response['payload'] ?? [];
        } catch (AmazonException) {
            return [];
        }

        $document = null;

        foreach ($payload['BuyerTaxInfo']['TaxClassifications'] ?? [] as $classification) {
            if (in_array($classification['Name'] ?? null, ['CPF', 'CNPJ'], true)) {
                $document = preg_replace('/\D/', '', (string) ($classification['Value'] ?? ''));
                break;
            }
        }

        return [
            'name' => $payload['BuyerName'] ?? null,
            'email' => $payload['BuyerEmail'] ?? null,
            'document' => $document ?: null,
        ];
    }

    private function extractState(string $state): string
    {
        $normalized = strtoupper(trim($state));

        if (strlen($normalized) === 2) {
            return $normalized;
        }

        return mb_substr($normalized, 0, 2) ?: 'NA';
    }

    /**
     * CORREÇÃO REAL (2026-08-07): a primeira versão deste método usava um
     * feedType inventado (`POST_INVOICE_CONFIRMATION`) sem confirmação
     * contra a doc oficial — verificado agora contra
     * developer-docs.amazon/sp-api/docs/feed-type-values e
     * invoicing-feed-type-values e esse feedType **não existe**. O que
     * existe de fato relacionado a nota fiscal na SP-API:
     * - `UPLOAD_VAT_INVOICE` (Feeds API) — é só pra "EU store (VAT
     *   program)", não cobre o Brasil.
     * - Invoices API (`invoices-v2024-06-19`) — **somente leitura**
     *   ("This API is only able to retrieve Brazilian FBA invoices"), serve
     *   pra baixar nota já emitida, não pra enviar uma.
     * - Shipment Invoicing API (`submitInvoice`) — existe e aceita upload
     *   (base64+MD5) mas é **exclusiva de "Brazilian FBA Onsite Orders"**
     *   ("You cannot use this API in other Amazon stores or with other
     *   fulfillment programs") — pedidos MFN (envio pelo próprio vendedor,
     *   o caso da Kazakora) ficam de fora.
     * Não encontrei, na documentação pública disponível, um endpoint SP-API
     * pra um vendedor MFN brasileiro enviar a NF-e pra Amazon. Fica como
     * stub explícito (mesmo padrão do TikTok/Shopee quando o endpoint real
     * não pôde ser confirmado) em vez de manter uma implementação
     * fabricada. Hipótese mais provável, a confirmar com o usuário: a NF-e
     * de pedido MFN talvez precise só viajar fisicamente com o pacote
     * (DANFE impresso junto da etiqueta, igual já acontece pro site
     * próprio) — se for esse o caso, o pipeline certo não é "enviar pro
     * canal", é anexar o DANFE ao PDF da etiqueta antes de imprimir (dá pra
     * reaproveitar App\Modules\Marketplace\Support\LabelProcessingService,
     * que já sabe fazer overlay em PDF de etiqueta).
     */
    public function submitInvoice(Order $order, Invoice $invoice): array
    {
        $this->ensureConfigured();

        throw new RuntimeException('Envio de nota fiscal pra Amazon ainda não implementado — endpoint SP-API real pra pedido MFN (não-FBA) no Brasil não confirmado na documentação pública. Ver comentário deste método.');
    }

    /**
     * Merchant Fulfillment API (MFN): compra o frete e já recebe a etiqueta
     * pronta na mesma resposta (síncrono — diferente do Mercado
     * Livre/Shopee). getEligibleShippingServices cota as opções
     * disponíveis, escolhe a mais barata, createShipment confirma a compra.
     * Peso/dimensões vêm do cadastro fiscal de cada produto do pedido — sem
     * inventar valor pra produto sem essa informação (mesmo princípio já
     * usado em FreightQuoteService::buildProductsPayload()), lança erro
     * explícito nesse caso em vez de arriscar uma cotação errada.
     */
    public function confirmShipping(Order $order): array
    {
        $this->ensureConfigured();

        $orderItemsResponse = $this->client->get("/orders/v0/orders/{$order->external_order_id}/orderItems");
        $itemList = [];

        foreach ($orderItemsResponse['payload']['OrderItems'] ?? [] as $item) {
            if (! isset($item['OrderItemId'])) {
                continue;
            }

            $itemList[] = [
                'OrderItemId' => (string) $item['OrderItemId'],
                'Quantity' => (int) ($item['QuantityOrdered'] ?? 1),
            ];
        }

        if (! $itemList) {
            throw new RuntimeException("Pedido #{$order->id} não tem itens retornados pela Amazon pra confirmar o envio.");
        }

        [$weightGrams, $dimensions] = $this->resolvePackageMeasurements($order);
        $company = Company::query()->first();

        // Address (MFN) exige AddressLine1/City/CountryCode/Email/Name/
        // Phone/PostalCode — confirmado contra o schema real
        // (merchantFulfillmentV0.json, "required" do definitions.Address).
        // Email é fácil de esquecer (nenhum outro canal deste projeto pede
        // e-mail da empresa pra nada) — sem ele a Amazon rejeita a cotação
        // inteira.
        if (! $company?->zip || ! $company->email) {
            throw new RuntimeException('Endereço/e-mail de origem (dados da empresa) não cadastrado — necessário pra cotar o envio na Amazon.');
        }

        $shipmentRequestDetails = [
            'AmazonOrderId' => $order->external_order_id,
            'SellerOrderId' => (string) $order->id,
            'ItemList' => $itemList,
            'ShipFromAddress' => [
                'Name' => $company->nome_fantasia ?: $company->razao_social,
                'AddressLine1' => trim("{$company->street}, {$company->number}"),
                'AddressLine2' => $company->complement,
                'City' => $company->city,
                // Confirmado contra o schema real: o campo aqui é
                // `StateOrProvinceCode`, diferente de `StateOrRegion` da
                // Orders API (schema distinto, mesmo conceito).
                'StateOrProvinceCode' => $company->state,
                'PostalCode' => preg_replace('/\D/', '', (string) $company->zip),
                'CountryCode' => 'BR',
                'Email' => $company->email,
                'Phone' => $company->phone,
            ],
            'PackageDimensions' => $dimensions,
            'Weight' => ['Value' => $weightGrams, 'Unit' => 'g'],
            'ShippingServiceOptions' => [
                // Enum real (confirmado no schema, DeliveryExperienceType):
                // DeliveryConfirmationWithAdultSignature/WithSignature/
                // WithoutSignature/NoTracking — "NoSignatureRequired" não
                // existe, era um valor inventado numa versão anterior deste
                // arquivo.
                'DeliveryExperience' => 'DeliveryConfirmationWithoutSignature',
                'CarrierWillPickUp' => false,
            ],
        ];

        $eligible = $this->client->post('/mfn/v0/eligibleShippingServices', ['ShipmentRequestDetails' => $shipmentRequestDetails]);
        $services = $eligible['payload']['ShippingServiceList'] ?? [];

        if (! $services) {
            throw new RuntimeException("Nenhum serviço de envio elegível retornado pela Amazon pro pedido #{$order->id}.");
        }

        usort($services, fn ($a, $b) => ($a['Rate']['Amount'] ?? PHP_FLOAT_MAX) <=> ($b['Rate']['Amount'] ?? PHP_FLOAT_MAX));
        $chosen = $services[0];

        // ShippingServiceOfferId é opcional (CreateShipmentRequest só exige
        // ShipmentRequestDetails+ShippingServiceId) — só entra no corpo
        // quando a cotação realmente devolveu um, nunca como null explícito
        // (schema estrito pode rejeitar um campo opcional mandado como null).
        $shipment = $this->client->post('/mfn/v0/shipments', array_filter([
            'ShipmentRequestDetails' => $shipmentRequestDetails,
            'ShippingServiceId' => $chosen['ShippingServiceId'],
            'ShippingServiceOfferId' => $chosen['ShippingServiceOfferId'] ?? null,
        ], fn ($value) => $value !== null));

        $payload = $shipment['payload'] ?? [];
        $shipmentId = (string) ($payload['ShipmentId'] ?? '');

        // MFN já devolve a etiqueta pronta nessa mesma chamada (diferente
        // dos outros canais, que processam de forma assíncrona) — guarda em
        // cache pra fetchLabel() consumir sem precisar de outra chamada.
        if ($shipmentId !== '' && isset($payload['Label']['FileContents']['Contents'])) {
            Cache::put(
                $this->labelCacheKey($shipmentId),
                [
                    'contents' => base64_decode((string) $payload['Label']['FileContents']['Contents']),
                    'content_type' => Str::contains(strtolower((string) ($payload['Label']['FileContents']['FileType'] ?? '')), 'pdf') ? 'application/pdf' : 'application/octet-stream',
                ],
                now()->addHours(4),
            );
        }

        return [
            'external_shipment_id' => $shipmentId ?: null,
            'tracking_code' => $payload['TrackingId'] ?? null,
            'shipping_method' => $payload['ShippingService']['CarrierName'] ?? 'merchant_fulfillment',
            'status' => $shipmentId !== '' ? 'confirmed' : 'error',
        ];
    }

    /**
     * @return array{0: float, 1: array{Length: float, Width: float, Height: float, Unit: string}}
     */
    private function resolvePackageMeasurements(Order $order): array
    {
        $order->loadMissing('items.product.fiscalData');

        $weightGrams = 0.0;
        $maxWidth = 0.0;
        $maxHeight = 0.0;
        $sumLength = 0.0;

        foreach ($order->items as $item) {
            $fiscal = $item->product?->fiscalData;

            if (! $fiscal || ! $fiscal->peso_bruto || ! $fiscal->altura_cm || ! $fiscal->largura_cm || ! $fiscal->profundidade_cm) {
                throw new RuntimeException("Produto \"{$item->product_name}\" sem peso/dimensões cadastrados — necessário pra cotar o envio na Amazon.");
            }

            $weightGrams += (float) $fiscal->peso_bruto * 1000 * $item->quantity;
            $maxWidth = max($maxWidth, (float) $fiscal->largura_cm);
            $maxHeight = max($maxHeight, (float) $fiscal->altura_cm);
            // Empilha o comprimento como aproximação de caixa única pro
            // pacote inteiro (mesma simplificação que outras integrações de
            // frete deste projeto já assumem, ver
            // FreightQuoteService::buildProductsPayload() pro equivalente na
            // cotação do site — lá cada produto vai como item separado pro
            // Melhor Envio decidir; aqui a Amazon exige uma única caixa por
            // shipment, então precisa consolidar).
            $sumLength += (float) $fiscal->profundidade_cm * $item->quantity;
        }

        return [
            round($weightGrams),
            ['Length' => $sumLength, 'Width' => $maxWidth, 'Height' => $maxHeight, 'Unit' => 'centimeters'],
        ];
    }

    /**
     * A etiqueta já foi baixada e cacheada por confirmShipping() (MFN é
     * síncrono). Se o cache já expirou (retry tardio, ex: depois de um
     * problema na nota fiscal atrasando o pipeline), rebusca via
     * getShipment — a Amazon mantém a etiqueta já comprada disponível pra
     * reconsulta, não precisa comprar de novo.
     */
    public function fetchLabel(Order $order): array
    {
        $this->ensureConfigured();

        $shipmentId = $this->resolveShipmentId($order);
        $cached = Cache::pull($this->labelCacheKey($shipmentId));

        if ($cached) {
            return ['ready' => true, 'contents' => $cached['contents'], 'content_type' => $cached['content_type']];
        }

        $shipment = $this->client->get("/mfn/v0/shipments/{$shipmentId}");
        $label = $shipment['payload']['Label']['FileContents'] ?? null;

        if (! $label || empty($label['Contents'])) {
            return ['ready' => false, 'contents' => null, 'content_type' => null];
        }

        return [
            'ready' => true,
            'contents' => base64_decode((string) $label['Contents']),
            'content_type' => Str::contains(strtolower((string) ($label['FileType'] ?? '')), 'pdf') ? 'application/pdf' : 'application/octet-stream',
        ];
    }

    private function labelCacheKey(string $shipmentId): string
    {
        return "amazon_mfn_label:{$shipmentId}";
    }

    private function resolveShipmentId(Order $order): string
    {
        $shipmentId = ChannelShipment::query()
            ->where('order_id', $order->id)
            ->where('channel', $this->channel())
            ->value('external_shipment_id');

        if (! $shipmentId) {
            throw new RuntimeException("Pedido #{$order->id} não tem shipment_id da Amazon registrado.");
        }

        return $shipmentId;
    }
}
