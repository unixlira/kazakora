<?php

namespace App\Modules\Marketplace\Drivers;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Drivers\Concerns\ReadsOrdersFromBling;
use App\Modules\Marketplace\Exceptions\PagamentoAmazonPendenteException;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Modules\Marketplace\Support\AmazonDbaShipping;
use App\Modules\Marketplace\Support\PagamentoAmazon;
use App\Services\Amazon\AmazonClient;
use App\Services\Amazon\Exceptions\AmazonException;
use App\Services\Bling\BlingOrderService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Amazon (Selling Partner API / SP-API) — docs:
 * https://developer-docs.amazon.com/sp-api
 *
 * Autenticação: LWA via App\Services\Amazon\AmazonAuthService/AmazonClient
 * (ver comentário lá pra assinatura/RDT).
 *
 * Pedido (Lira, 04/11/2026: "o Bling é só para o TikTok Shop"): sempre
 * direto pela SP-API (Orders API v0), chegando pelo aviso ORDER_CHANGE da
 * fila SQS (ProcessarNotificacaoAmazon) e pela varredura orders:sync-amazon.
 * O Bling não entra em nada da Amazon: nem pedido, nem nota, nem etiqueta.
 *
 * Envio (pedido do Lira 03/11/2026): DBA, Delivery by Amazon / Easy Ship,
 * levado ao Ponto de Envio. NF-e do KazaKora enviada à Amazon
 * (submitInvoice), pacote agendado em horário de drop-off, etiqueta TBR da
 * Amazon na impressão automática. Nunca Correios, nunca compra de frete
 * (MFN), nunca coleta em casa. Ver AmazonDbaShipping.
 *
 * publishProduct/updateStock/unpublishProduct ficam como stub: o payload
 * real depende do `productType` de cada categoria (Listings Items API).
 */
class AmazonDriver extends AbstractMarketplaceDriver
{
    use ReadsOrdersFromBling;

    private const LISTINGS_API_VERSION = '2021-08-01';

    public function __construct(
        private readonly AmazonClient $client,
        private readonly BlingOrderService $blingOrders,
    ) {}

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
        throw new RuntimeException('Integração de publicação de produto com a Amazon ainda não implementada.');
    }

    public function updateStock(Product $product, ProductChannelListing $listing): void
    {
        $this->ensureConfigured();

        // TODO: PATCH no mesmo endpoint acima, op=replace no atributo
        // `fulfillment_availability` — mesmo bloqueio de schema do método
        // acima.
        throw new RuntimeException('Integração de publicação de produto com a Amazon ainda não implementada.');
    }

    public function unpublishProduct(ProductChannelListing $listing): void
    {
        $this->ensureConfigured();

        // TODO: DELETE /listings/2021-08-01/items/{sellerId}/{sku}.
        throw new RuntimeException('Integração de publicação de produto com a Amazon ainda não implementada.');
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

        return $this->importOrderFromSpApi($externalOrderId);
    }

    private function importOrderFromSpApi(string $externalOrderId): array
    {
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
            'status' => self::statusDoPedido($order),
            'subtotal' => round($itemsSubtotal, 2),
            'shipping_cost' => $shippingCost,
            'total' => round($total, 2),
            'buyer_name' => $buyer['name'] ?? $address['name'] ?? 'Comprador Amazon',
            'buyer_document' => $buyer['document'] ?? null,
            'buyer_email' => $buyer['email'] ?? null,
            'buyer_phone' => $address['phone'] ?? null,
            'shipping_zip' => $address['zip'] ?? '00000000',
            'shipping_street' => $address['street'] ?? 'Não informado',
            'shipping_number' => $address['number'] ?? 'S/N',
            'shipping_complement' => $address['complement'] ?? null,
            'shipping_neighborhood' => $address['neighborhood'] ?? $order['BuyerInfo']['BuyerCounty'] ?? 'Não informado',
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
            'placed_at' => isset($order['PurchaseDate']) ? Carbon::parse($order['PurchaseDate'])->setTimezone(config('app.timezone')) : null,
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
    public static function mapOrderStatus(string $status): string
    {
        return match ($status) {
            'Unshipped', 'PartiallyShipped' => Order::STATUS_PAID,
            'Shipped' => Order::STATUS_SHIPPED,
            'Canceled', 'Unfulfillable' => Order::STATUS_CANCELLED,
            default => Order::STATUS_AWAITING_PAYMENT,
        };
    }

    /**
     * Pedido DBA (Easy Ship) vira "Shipped" na Amazon assim que o pacote é
     * agendado, antes de sair da bancada (visto em 03/11/2026 no
     * 701-9739703-8713050: Shipped com EasyShipShipmentStatus
     * PendingDropOff). Enquanto o pacote não foi entregue ao Ponto de Envio
     * o pedido continua "pago" aqui, na fila do KoraSync.
     *
     * @param  array<string, mixed>  $order
     */
    public static function statusDoPedido(array $order): string
    {
        $status = (string) ($order['OrderStatus'] ?? '');

        if ($status === 'Shipped' && in_array($order['EasyShipShipmentStatus'] ?? null, self::EASY_SHIP_ANTES_DA_POSTAGEM, true)) {
            return Order::STATUS_PAID;
        }

        return self::mapOrderStatus($status);
    }

    /** EasyShipShipmentStatus de pacote que ainda não saiu da loja. */
    public const EASY_SHIP_ANTES_DA_POSTAGEM = ['PendingSchedule', 'PendingPickUp', 'PendingDropOff', 'LabelCanceled'];

    /**
     * `getOrderAddress`, dado pessoal — exige RDT com dataElements
     * `shippingAddress`. Nunca lança: um pedido sem endereço liberado (ex:
     * retirada/loja física, ou janela de acesso a PII já expirada) não pode
     * travar a importação, só cai nos fallbacks "Não informado".
     *
     * @return array{name: ?string, zip: ?string, street: ?string, number: ?string, neighborhood: ?string, complement: ?string, city: ?string, state: ?string, phone: ?string}
     */
    private function fetchAddress(string $orderId): array
    {
        $address = $this->lerComOuSemRdt("/orders/v0/orders/{$orderId}/address", 'shippingAddress')['ShippingAddress'] ?? [];

        if (empty($address)) {
            return [];
        }

        // Amazon Brasil manda rua, número, complemento e bairro separados
        // em ExtendedFields (conferido no 701-9739703-8713050, 03/11).
        $extra = is_array($address['ExtendedFields'] ?? null) ? $address['ExtendedFields'] : [];
        $street = filled($extra['StreetName'] ?? null)
            ? trim((string) $extra['StreetName'])
            : trim(($address['AddressLine1'] ?? '').(isset($address['AddressLine2']) ? ' '.$address['AddressLine2'] : ''));

        return [
            'name' => $address['Name'] ?? null,
            'zip' => isset($address['PostalCode']) ? preg_replace('/\D/', '', (string) $address['PostalCode']) : null,
            'street' => $street ?: null,
            'number' => filled($extra['StreetNumber'] ?? null) ? trim((string) $extra['StreetNumber']) : null,
            'neighborhood' => filled($extra['Neighborhood'] ?? null) ? trim((string) $extra['Neighborhood']) : null,
            'complement' => filled($extra['Complement'] ?? null) ? trim((string) $extra['Complement']) : ($address['AddressLine3'] ?? null),
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
        $payload = $this->lerComOuSemRdt("/orders/v0/orders/{$orderId}/buyerInfo", 'buyerInfo');

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

    /**
     * Dado pessoal do pedido: primeiro com RDT. Em 03/11/2026 a Amazon
     * recusou o RDT ("Application does not have access to one or more
     * requested data elements") e devolveu o endereço e o CPF na chamada
     * comum do mesmo pedido DBA; por isso tenta a comum quando o RDT é
     * recusado. Nunca lança: sem dado, a importação usa os padrões.
     *
     * @return array<string, mixed>
     */
    private function lerComOuSemRdt(string $path, string $dataElement): array
    {
        try {
            return $this->client->restrictedGet($path, [$dataElement])['payload'] ?? [];
        } catch (AmazonException) {
            try {
                return $this->client->get($path)['payload'] ?? [];
            } catch (AmazonException) {
                return [];
            }
        }
    }

    /**
     * Teste de 2026-10-16: "São Paulo" virava "Sã" (byte cortado, o MySQL
     * recusa) e "Paraná" virava "PA" (UF de outro estado). Mesmo mapa de
     * nome → sigla da Shopee, comparado sem acento.
     */
    private function extractState(string $state): string
    {
        $normalized = strtoupper(trim(Str::ascii($state)));

        if (isset(ShopeeDriver::STATE_NAMES_TO_UF[$normalized])) {
            return ShopeeDriver::STATE_NAMES_TO_UF[$normalized];
        }

        return mb_substr($normalized, 0, 2) ?: 'NA';
    }

    /**
     * DBA (pedido do Lira 03/11/2026): a NF-e autorizada vai para a Amazon
     * pela Delivery by Amazon API (POST /delivery/2022-07-01/invoice,
     * programType "EasyShip" = DBA no Brasil, XML da SEFAZ em base64 + MD5).
     * O agendamento no Ponto de Envio só sai com a nota aceita (ver
     * AmazonDbaShipping). "sent" faz ChannelInvoiceSubmissionService
     * disparar o agendamento na hora.
     *
     * Nota já aceita ou em análise lá (enviada antes, ou à mão no Seller
     * Central) não é enviada de novo.
     */
    public function submitInvoice(Order $order, Invoice $invoice): array
    {
        $this->ensureConfigured();

        $marketplaceId = config('services.amazon.marketplace_id');
        $amazonOrderId = (string) $order->external_order_id;

        $situacao = $this->client->get('/delivery/2022-07-01/invoice/status', [
            'marketplaceId' => $marketplaceId,
            'programType' => 'EasyShip',
            'orderId' => $amazonOrderId,
            'invoiceType' => 'Outbound',
        ]);

        $jaLa = collect($situacao['invoices'] ?? [])
            ->first(fn ($nota) => ($nota['id'] ?? null) === $invoice->chave_acesso && in_array($nota['status'] ?? null, ['Accepted', 'Processing'], true));

        if ($jaLa) {
            return [
                'status' => 'sent',
                'external_reference' => $invoice->chave_acesso,
                'response' => ['info' => "NF-e já está na Amazon ({$jaLa['status']})."],
            ];
        }

        if (! $invoice->xml_path || ! Storage::disk('local')->exists($invoice->xml_path)) {
            throw new RuntimeException("Pedido #{$order->id}: XML autorizado da NF-e não encontrado para enviar à Amazon.");
        }

        $xml = Storage::disk('local')->get($invoice->xml_path);

        $resposta = $this->client->post('/delivery/2022-07-01/invoice?orderId='.rawurlencode($amazonOrderId), [
            'invoiceContent' => base64_encode($xml),
            'contentMD5Value' => base64_encode(md5($xml, true)),
            'invoiceType' => 'Outbound',
            'programType' => 'EasyShip',
            'marketplaceId' => $marketplaceId,
        ]);

        return [
            'status' => 'sent',
            'external_reference' => $invoice->chave_acesso,
            'response' => $resposta ?: ['info' => 'NF-e enviada à Amazon (DBA).'],
        ];
    }

    /**
     * DBA (pedido do Lira 03/11/2026): pacote agendado no Easy Ship para o
     * Ponto de Envio, nunca Correios, nunca compra de frete. Ver
     * AmazonDbaShipping. Sem SP-API conectada não há como agendar: lança
     * MarketplaceNotConfiguredException e o job avisa no sino.
     */
    public function confirmShipping(Order $order): array
    {
        $this->ensureConfigured();

        if (app(PagamentoAmazon::class)->segurarExpedicao($order, 'Etiqueta', OrderFulfillmentEvent::STEP_SHIPPING_CONFIRMED)) {
            throw new PagamentoAmazonPendenteException("Etiqueta não gerada: pagamento do pedido {$order->external_order_id} ainda não confirmado na Amazon.");
        }

        return app(AmazonDbaShipping::class)->agendar($order);
    }

    /** Etiqueta do DBA baixada no agendamento (AmazonDbaShipping). */
    public function fetchLabel(Order $order): array
    {
        $etiqueta = app(AmazonDbaShipping::class)->etiqueta($order);

        return $etiqueta !== null
            ? ['ready' => true, 'contents' => $etiqueta, 'content_type' => 'application/pdf']
            : ['ready' => false, 'contents' => null, 'content_type' => null];
    }

    /**
     * O item que chega pelo Bling traz em `codigo` o SKU do anúncio na
     * Amazon (SellerSKU). Casa, nesta ordem:
     *
     * 1. SKU igual ao do catálogo (sem diferenciar maiúscula/espaço).
     * 2. NOME do item contra o catálogo, com a COR decidindo entre as
     *    variações — achado real 2026-09-25 (pedido #2504): a Amazon mandou
     *    códigos que não são os nossos SKUs, e os itens ficaram sem produto,
     *    sem foto e sem SKU no KoraSync. "Mini Power Bank ... Tipo C Preto"
     *    é o Power Bank PRETO; o nome sozinho empata as 4 cores (mesmo nome
     *    no catálogo, ver TikTokShopDriver::matchByNameSimilarity()), mas a
     *    cor escrita no título desempata.
     *
     * Sem cor no título e mais de uma variação possível, NÃO escolhe: cor
     * errada é encomenda errada na casa do cliente. O item fica pro botão
     * "Vincular produto" do KoraSync, uma vez só por código.
     */
    public function autoImportProduct(string $externalId, int $quantitySold = 0, ?string $externalModelId = null): ?Product
    {
        $product = Product::query()->whereRaw('UPPER(TRIM(sku)) = ?', [mb_strtoupper(trim($externalId))])->first()
            ?? $this->casarPorNome($externalId);

        if (! $product) {
            return null;
        }

        try {
            ProductChannelListing::query()->firstOrCreate(
                ['channel' => $this->channel(), 'external_id' => $externalId],
                ['product_id' => $product->id, 'is_enabled' => true, 'status' => ProductChannelListing::STATUS_PUBLISHED, 'last_synced_at' => now()],
            );
        } catch (UniqueConstraintViolationException) {
            // Produto já tem listing Amazon com outro SKU — o vínculo é só
            // atalho, o produto certo já foi achado (mesmo caso do TikTok).
        }

        return $product;
    }

    /** Mesmos limites do TikTok (TikTokShopDriver): 55% de semelhança, empate dentro de 10 pontos. */
    private const SEMELHANCA_MINIMA = 55.0;

    private const MARGEM_DE_EMPATE = 10.0;

    private function casarPorNome(string $externalId): ?Product
    {
        // O nome que o canal mandou fica no próprio item sem vínculo
        // (OrderImportService grava external_name quando não acha produto)
        // — serve tanto na importação quanto no relink de 30 em 30 minutos.
        $nome = OrderItem::query()
            ->where('external_item_id', $externalId)
            ->whereNull('product_id')
            ->whereHas('order', fn ($query) => $query->where('origin', $this->channel()))
            ->latest('id')
            ->value('product_name');

        if (! $nome) {
            return null;
        }

        $pontuados = Product::query()->naKazaKora()->get(['id', 'name', 'sku', 'color'])
            ->map(function (Product $candidato) use ($nome) {
                similar_text(mb_strtolower($nome), mb_strtolower($candidato->name), $percentual);

                return ['produto' => $candidato, 'pontos' => $percentual];
            })
            ->filter(fn ($linha) => $linha['pontos'] >= self::SEMELHANCA_MINIMA);

        if ($pontuados->isEmpty()) {
            return null;
        }

        $melhor = $pontuados->max('pontos');
        $familia = $pontuados->filter(fn ($linha) => $linha['pontos'] >= $melhor - self::MARGEM_DE_EMPATE)->pluck('produto');

        if ($familia->count() === 1) {
            return $familia->first();
        }

        $titulo = ' '.Str::of($nome)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' ').' ';
        $daCor = $familia->filter(function (Product $produto) use ($titulo) {
            $cor = trim((string) Str::of((string) $produto->color)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', ' '));

            return $cor !== '' && str_contains($titulo, " {$cor} ");
        });

        if ($daCor->count() === 1) {
            return $daCor->first();
        }

        Log::warning('amazon.item.variacao_ambigua', [
            'external_id' => $externalId,
            'nome' => $nome,
            'candidatos' => $familia->pluck('sku')->all(),
        ]);

        return null;
    }

    /**
     * Amazon não passa mais pelo Bling (Lira, 04/11/2026: "nós somos
     * dependentes do Bling somente para falar com o TikTok Shop"). Fica
     * sempre false para os pontos que ainda perguntam (nota, KoraSync,
     * SyncBlingInvoices) seguirem o caminho direto da SP-API, mesmo com
     * BLING_AMAZON_LOJA_ID preenchido.
     */
    public function viaBling(): bool
    {
        return false;
    }

    /**
     * Confirma o envio na Amazon pela SP-API — o pedido vira "Enviado" lá
     * e o comprador recebe o rastreio. Pedido explícito 2026-09-25: o
     * Bling NÃO repassa rastreio pra Amazon (a integração dele só importa
     * vendas e taxas), então esta é a única ponte automática.
     *
     * POST /orders/v0/orders/{id}/shipmentConfirmation (Orders API v0).
     * carrierCode "Other" + carrierName "Correios": o nome da
     * transportadora vai escrito, sem depender de "Correios" estar na
     * lista fechada de códigos da Amazon.
     */
    public function confirmarEnvio(Order $order, string $rastreio, string $servico): void
    {
        $this->ensureConfigured();

        $itens = collect($this->client->get("/orders/v0/orders/{$order->external_order_id}/orderItems")['payload']['OrderItems'] ?? [])
            ->filter(fn ($item) => isset($item['OrderItemId']))
            ->map(fn ($item) => ['orderItemId' => (string) $item['OrderItemId'], 'quantity' => (int) ($item['QuantityOrdered'] ?? 1)])
            ->values()
            ->all();

        if ($itens === []) {
            throw new RuntimeException("A Amazon não devolveu os itens do pedido {$order->external_order_id} pra confirmar o envio.");
        }

        $this->client->post("/orders/v0/orders/{$order->external_order_id}/shipmentConfirmation", [
            'marketplaceId' => config('services.amazon.marketplace_id'),
            'packageDetail' => [
                'packageReferenceId' => '1',
                'carrierCode' => 'Other',
                'carrierName' => 'Correios',
                'shippingMethod' => $servico,
                'trackingNumber' => $rastreio,
                'shipDate' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
                'orderItems' => $itens,
            ],
        ]);
    }

    /**
     * O pedido já está "Enviado" (ou entregue) na Amazon? Usado quando a
     * confirmação de envio é recusada por já ter sido feita à mão.
     */
    public function jaEnviado(Order $order): bool
    {
        $this->ensureConfigured();

        $status = $this->client->get("/orders/v0/orders/{$order->external_order_id}")['payload']['OrderStatus'] ?? null;

        return in_array($status, ['Shipped', 'InvoiceUnconfirmed'], true);
    }

    protected function blingBuyerFallbackName(): string
    {
        return 'Comprador Amazon';
    }

    protected function blingShippingMethodFallback(): string
    {
        return 'Amazon';
    }
}
