<?php

namespace App\Modules\Marketplace\Drivers;

use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Drivers\Concerns\ReadsOrdersFromBling;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Exceptions\PagamentoAmazonPendenteException;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Modules\Marketplace\Support\CorreiosAutoShipping;
use App\Modules\Marketplace\Support\CorreiosLabelPdf;
use App\Modules\Marketplace\Support\PagamentoAmazon;
use App\Services\Amazon\AmazonClient;
use App\Services\Bling\BlingOrderService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Amazon (Selling Partner API / SP-API) — docs:
 * https://developer-docs.amazon.com/sp-api
 *
 * DIRETO PELA SP-API (decisão do Lira em 16/10/2026), igual Shopee e
 * Mercado Livre: o pedido entra pela Orders API v2026-01-01 (a única que
 * entrega CPF e endereço com número e bairro sem RDT, conferido ao vivo; a
 * v0 sai do ar em 27/03/2027), a NF-e é a NOSSA (GenerateInvoiceJob), o
 * envio é pelos CORREIOS da loja (nota autorizada com chave → pré-postagem
 * → etiqueta com QR, ver CorreiosAutoShipping) e o rastreio volta pra
 * Amazon pelo confirmShipment com carrierCode "Correios". Estoque e preço
 * vão pela Listings Items API (patch em fulfillment_availability e
 * purchasable_offer do SKU já anunciado).
 *
 * Anúncio NOVO continua sendo criado no Seller Central: o payload depende
 * do productType de cada categoria. Depois de criado, basta vincular o SKU.
 *
 * Liga com AMAZON_DIRETO=true no .env do servidor (só a produção: o
 * homolog usa a mesma conta real da Amazon e emite NF-e em produção).
 *
 * VIA BLING (fluxo de 25/09/2026, o que vale sem AMAZON_DIRETO, ou com a
 * SP-API desconectada): pedido e nota vêm do Bling (ReadsOrdersFromBling),
 * como o TikTok Shop, e estoque/preço não saem daqui. O envio pelos
 * Correios e a confirmação do rastreio pela SP-API são os mesmos.
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

    /**
     * Sincroniza preço e estoque de um SKU que JÁ está anunciado na Amazon
     * (external_id = SellerSKU). Criar anúncio novo exige o productType e
     * os atributos de cada categoria: fica no Seller Central.
     *
     * Preço só vai se o canal Amazon tiver preço próprio cadastrado: em
     * 16/10/2026 a Amazon vendia o power bank a R$ 55,90 e o preço base do
     * produto era R$ 49,99. Cair no preço base baixaria o preço lá sem
     * ninguém decidir.
     */
    public function publishProduct(Product $product, ProductChannelListing $listing): string
    {
        $this->garantirEnvioDeAnuncio();

        if (! $listing->external_id) {
            throw new RuntimeException('Anúncio novo na Amazon é criado no Seller Central. Depois de criado, vincule o SKU da Amazon a este produto e sincronize: preço e estoque passam a ir daqui.');
        }

        $patches = [$this->patchEstoque(max(0, (int) $product->stock))];

        if ($listing->price !== null) {
            $patches[] = $this->patchPreco((float) $listing->price);
        }

        $this->patchListing($listing->external_id, $patches);

        return $listing->external_id;
    }

    public function updateStock(Product $product, ProductChannelListing $listing): void
    {
        $this->garantirEnvioDeAnuncio();

        $this->patchListing($listing->external_id, [$this->patchEstoque(max(0, (int) $product->stock))]);
    }

    /**
     * Zera o estoque em vez de apagar o anúncio (DELETE na Amazon perde o
     * histórico da oferta). Volta a vender na próxima sincronização.
     */
    public function unpublishProduct(ProductChannelListing $listing): void
    {
        $this->garantirEnvioDeAnuncio();

        $this->patchListing($listing->external_id, [$this->patchEstoque(0)]);
    }

    /**
     * PATCH /listings/2021-08-01/items/{sellerId}/{sku}. O corpo exige o
     * productType: vem do próprio anúncio (summaries), guardado por 1 dia.
     *
     * @param  array<int, array<string, mixed>>  $patches
     */
    private function patchListing(string $sku, array $patches): void
    {
        $sellerId = (string) $this->ensureConfigured()->seller_id;
        $marketplaceId = (string) config('services.amazon.marketplace_id');

        if ($sellerId === '') {
            throw new RuntimeException('Conta da Amazon sem Seller ID: reconecte a SP-API em Integrações.');
        }

        $path = '/listings/'.self::LISTINGS_API_VERSION.'/items/'.rawurlencode($sellerId).'/'.rawurlencode($sku);

        $productType = Cache::remember("amazon:product-type:{$sku}", now()->addDay(), function () use ($path, $marketplaceId, $sku) {
            $item = $this->client->get($path, ['marketplaceIds' => $marketplaceId, 'includedData' => 'summaries']);

            return $item['summaries'][0]['productType']
                ?? throw new RuntimeException("SKU {$sku} não encontrado nos anúncios da Amazon.");
        });

        $resposta = $this->client->patch($path.'?'.http_build_query(['marketplaceIds' => $marketplaceId]), [
            'productType' => $productType,
            'patches' => $patches,
        ]);

        // A Amazon responde 200 mesmo recusando o patch: o motivo vem em issues.
        $erros = collect($resposta['issues'] ?? [])->where('severity', 'ERROR');

        if (($resposta['status'] ?? null) === 'INVALID' || $erros->isNotEmpty()) {
            throw new RuntimeException("Amazon recusou a atualização do SKU {$sku}: ".$erros->pluck('message')->implode('; '));
        }
    }

    /** @return array<string, mixed> */
    private function patchEstoque(int $quantidade): array
    {
        return [
            'op' => 'merge',
            'path' => '/attributes/fulfillment_availability',
            'value' => [['fulfillment_channel_code' => 'DEFAULT', 'quantity' => $quantidade]],
        ];
    }

    /** @return array<string, mixed> */
    private function patchPreco(float $preco): array
    {
        return [
            'op' => 'merge',
            'path' => '/attributes/purchasable_offer',
            'value' => [[
                'marketplace_id' => config('services.amazon.marketplace_id'),
                'currency' => 'BRL',
                'audience' => 'ALL',
                'our_price' => [['schedule' => [['value_with_tax' => round($preco, 2)]]]],
            ]],
        ];
    }

    /**
     * Orders API v2026-01-01 (getOrder com includedData). Conferido ao vivo
     * em 16/10/2026: a v0 recusa o RDT de shippingAddress/buyerInfo pro
     * nosso app, e a v2026 entrega sem RDT o CPF (tax.taxRegistrations,
     * BUYER/CPF) e o endereço com rua, número, bairro e complemento
     * separados (recipient.deliveryAddress.extendedFields). Sem número a
     * nota e a etiqueta não saem (BUG REAL 2026-10-08, Correios devolveram).
     */
    public function importOrder(string $externalOrderId): array
    {
        if ($this->viaBling()) {
            return $this->comPagamentoConferido($this->importOrderFromBling($externalOrderId));
        }

        $order = $this->buscarPedido($externalOrderId, 'BUYER,RECIPIENT,TAX,FULFILLMENT,PROCEEDS');

        $itemsSubtotal = 0.0;
        $items = [];

        foreach ($order['orderItems'] ?? [] as $item) {
            $sku = (string) ($item['product']['sellerSku'] ?? '');
            $quantity = (int) ($item['quantityOrdered'] ?? 0);
            $unitPrice = round((float) ($item['product']['price']['unitPrice']['amount'] ?? 0), 2);

            if ($sku === '' || $quantity < 1) {
                continue;
            }

            $itemsSubtotal += $unitPrice * $quantity;

            $items[] = [
                'external_id' => $sku,
                'external_name' => $item['product']['title'] ?? null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
            ];
        }

        $proceeds = collect($order['proceeds']['breakdowns'] ?? []);
        $shippingCost = round((float) $proceeds->where('type', 'SHIPPING')->sum(fn ($b) => (float) ($b['subtotal']['amount'] ?? 0)), 2);
        $total = round((float) ($order['proceeds']['grandTotal']['amount'] ?? $itemsSubtotal + $shippingCost), 2);

        $address = $order['recipient']['deliveryAddress'] ?? [];
        $extended = $address['extendedFields'] ?? [];

        return [
            'external_order_id' => (string) ($order['orderId'] ?? $externalOrderId),
            'status' => self::mapFulfillmentStatus((string) ($order['fulfillment']['fulfillmentStatus'] ?? '')),
            'subtotal' => round($itemsSubtotal, 2),
            'shipping_cost' => $shippingCost,
            'total' => $total,
            // Promoção da Amazon: o comprador paga menos que itens + frete.
            // Sem o desconto a SEFAZ recusa a nota (vNF ≠ soma), mesmo caso
            // do TikTok em ReadsOrdersFromBling.
            'discount_amount' => round(max(0, $itemsSubtotal + $shippingCost - $total), 2),
            'buyer_name' => $order['buyer']['buyerName'] ?? $address['name'] ?? 'Comprador Amazon',
            'buyer_document' => $this->documentoDoComprador($order['tax']['taxRegistrations'] ?? []),
            'buyer_email' => $order['buyer']['buyerEmail'] ?? null,
            'buyer_phone' => $address['phone'] ?? null,
            'shipping_zip' => preg_replace('/\D/', '', (string) ($address['postalCode'] ?? '')) ?: '00000000',
            'shipping_street' => ($extended['streetName'] ?? null) ?: ($address['addressLine1'] ?? null) ?: 'Não informado',
            'shipping_number' => ($extended['streetNumber'] ?? null) ?: 'S/N',
            'shipping_complement' => ($extended['complement'] ?? null) ?: ($extended === [] ? ($address['addressLine2'] ?? null) : null),
            'shipping_neighborhood' => ($extended['neighborhood'] ?? null) ?: ($address['district'] ?? null) ?: 'Não informado',
            'shipping_city' => $address['city'] ?? 'Não informado',
            'shipping_state' => $this->extractState((string) ($address['stateOrRegion'] ?? '')),
            'external_shipment_id' => null,
            // createdTime vem em UTC ("...Z"): sem ->setTimezone() o pedido
            // feito à noite em SP vira madrugada do dia seguinte no banco e
            // vaza pra fila "só hoje" do KoraSync.
            'placed_at' => isset($order['createdTime']) ? \Illuminate\Support\Carbon::parse($order['createdTime'])->setTimezone(config('app.timezone')) : null,
            'items' => $items,
        ];
    }

    /**
     * O Bling não sabe se a Amazon já aprovou o pagamento: a situação dele
     * vira "pago" aqui (mapBlingOrderStatus()) mesmo com o pedido "Pending"
     * na Amazon. Pago só com a Amazon dizendo que está pago (PagamentoAmazon);
     * sem conseguir perguntar, fica aguardando e a varredura de hora em hora
     * confere de novo.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function comPagamentoConferido(array $data): array
    {
        if ($data['status'] !== Order::STATUS_PAID) {
            return $data;
        }

        try {
            $status = app(PagamentoAmazon::class)->statusNaAmazon((string) $data['external_order_id']);
        } catch (Throwable $e) {
            Log::channel('amazon')->warning('amazon.pagamento_nao_conferido', [
                'amazon_order_id' => $data['external_order_id'],
                'erro' => $e->getMessage(),
            ]);

            return ['status' => Order::STATUS_AWAITING_PAYMENT] + $data;
        }

        return ['status' => PagamentoAmazon::statusDoPedido($status)] + $data;
    }

    /**
     * GET /orders/2026-01-01/orders/{id}. Lança se a Amazon não achar o
     * pedido (o AmazonException de 429 sobe pro job tentar de novo).
     *
     * @return array<string, mixed>
     */
    private function buscarPedido(string $externalOrderId, string $includedData): array
    {
        $this->ensureConfigured();

        $resposta = $this->client->get("/orders/2026-01-01/orders/{$externalOrderId}", ['includedData' => $includedData]);
        $order = $resposta['order'] ?? null;

        if (! $order) {
            throw new RuntimeException("Pedido {$externalOrderId} não encontrado na Amazon.");
        }

        return $order;
    }

    /**
     * CPF ou CNPJ do comprador: taxRegistrations com entityType BUYER (as
     * outras entradas são da loja e da própria Amazon).
     *
     * @param  array<int, array<string, mixed>>  $registros
     */
    private function documentoDoComprador(array $registros): ?string
    {
        $documento = collect($registros)
            ->first(fn ($r) => ($r['entityType'] ?? null) === 'BUYER' && in_array($r['taxRegistrationType'] ?? null, ['CPF', 'CNPJ'], true));

        return preg_replace('/\D/', '', (string) ($documento['taxRegistrationNumber'] ?? '')) ?: null;
    }

    /**
     * fulfillment.fulfillmentStatus da Orders API v2026-01-01. Como na v0,
     * a Amazon só libera o pedido depois do pagamento: UNSHIPPED já é pago.
     */
    public static function mapFulfillmentStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'UNSHIPPED', 'PARTIALLY_SHIPPED' => Order::STATUS_PAID,
            'SHIPPED', 'DELIVERED' => Order::STATUS_SHIPPED,
            'CANCELLED', 'CANCELED', 'UNFULFILLABLE' => Order::STATUS_CANCELLED,
            default => Order::STATUS_AWAITING_PAYMENT,
        };
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
     * Não existe na SP-API endpoint pra vendedor MFN brasileiro enviar NF-e
     * (UPLOAD_VAT_INVOICE é só Europa; Invoices API e Shipment Invoicing
     * são só FBA, conferido em 2026-08-07). A nota segue com o objeto, com
     * a chave na pré-postagem dos Correios. "sent" é o que faz
     * ChannelInvoiceSubmissionService disparar a pré-postagem na hora.
     */
    public function submitInvoice(Order $order, Invoice $invoice): array
    {
        return [
            'status' => 'sent',
            'external_reference' => null,
            'response' => ['info' => 'Amazon: a NF-e vai com o objeto, chave na pré-postagem dos Correios.'],
        ];
    }

    /**
     * Envio pelos Correios da loja (pré-postagem com a chave da nota), nos
     * dois caminhos. Nunca compra frete na Amazon (Merchant Fulfillment):
     * não é como a loja trabalha. O rastreio vai pra Amazon pelo
     * ConfirmAmazonShipment assim que a etiqueta fica pronta.
     */
    public function confirmShipping(Order $order): array
    {
        // Regra do Lira (16/10/2026): etiqueta só com pagamento confirmado
        // na Amazon, nos dois caminhos.
        if (app(PagamentoAmazon::class)->segurarExpedicao($order, 'Etiqueta', OrderFulfillmentEvent::STEP_SHIPPING_CONFIRMED)) {
            throw new PagamentoAmazonPendenteException("Etiqueta não gerada: pagamento do pedido {$order->external_order_id} ainda não confirmado na Amazon.");
        }

        $correios = app(CorreiosAutoShipping::class);

        if ($this->viaBling()) {
            // Pedido que alguém já despachou pelo Bling (nota e envio
            // feitos à mão — caso real de 25/09): o rastreio já está lá.
            // Gerar pré-postagem agora seria um segundo objeto nos Correios
            // e uma etiqueta a mais na bancada. Tira da fila e não mexe.
            if (! $correios->geradaPara($order) && ($rastreio = $this->rastreioJaNoBling($order))) {
                app(\App\Modules\Marketplace\Support\OrderImportService::class)->syncStatus($order, Order::STATUS_SHIPPED);

                return [
                    'external_shipment_id' => null,
                    'tracking_code' => $rastreio,
                    'shipping_method' => 'Enviado pelo Bling (fora do KazaKora)',
                    'status' => 'confirmed',
                ];
            }
        }

        $prePostagem = $correios->confirm($order);

        if ($this->viaBling()) {
            \App\Jobs\InformAmazonShipmentToBling::dispatch($order->id)->delay(now()->addMinute())->afterCommit();
        }

        // Rastreio pra Amazon — o job espera a etiqueta ficar pronta.
        \App\Jobs\ConfirmAmazonShipment::dispatch($order->id)->delay(now()->addMinute())->afterCommit();

        return [
            'external_shipment_id' => $prePostagem->correios_id,
            'tracking_code' => $prePostagem->codigo_objeto,
            'shipping_method' => 'Correios '.$prePostagem->service_label,
            'status' => 'confirmed',
        ];
    }

    /** Etiqueta da pré-postagem dos Correios, nos dois caminhos. */
    public function fetchLabel(Order $order): array
    {
        $correios = app(CorreiosAutoShipping::class);
        $prePostagem = $correios->geradaPara($order);

        // Etiqueta que não descreve mais o pacote não vai pra impressora.
        if ($prePostagem && ($problema = $correios->problemaDaEtiqueta($order, $prePostagem))) {
            Log::warning('correios.etiqueta_bloqueada', ['order_id' => $order->id, 'motivo' => $problema]);

            return ['ready' => false, 'contents' => null, 'content_type' => null];
        }

        return $prePostagem
            ? ['ready' => true, 'contents' => app(CorreiosLabelPdf::class)->render($prePostagem), 'content_type' => 'application/pdf']
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
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Produto já tem listing Amazon com outro SKU — o vínculo é só
            // atalho, o produto certo já foi achado (mesmo caso do TikTok).
        }

        return $product;
    }

    /** Código de rastreio que o pedido já tem no Bling, se alguém já despachou por lá. */
    private function rastreioJaNoBling(Order $order): ?string
    {
        $pedido = $this->blingOrders->findByOrderNumber((string) $order->external_order_id, $this->blingOrders->amazonLojaId());

        return collect($pedido['transporte']['volumes'] ?? [])
            ->pluck('codigoRastreamento')
            ->map(fn ($codigo) => trim((string) $codigo))
            ->first(fn ($codigo) => $codigo !== '') ?: null;
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

        $pontuados = Product::query()->where('is_active', true)->get(['id', 'name', 'sku', 'color'])
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
     * Pedido e nota pelo Bling (fluxo de 25/09/2026)? Sim enquanto houver
     * loja Amazon no Bling e o servidor não tiver AMAZON_DIRETO=true; e
     * também, como rede de segurança, com a SP-API desconectada.
     */
    public function viaBling(): bool
    {
        if ($this->blingOrders->amazonLojaId() === null) {
            return false;
        }

        return ! $this->direto() || ! $this->isConfigured();
    }

    /** AMAZON_DIRETO=true: pedido, nota, estoque e preço sem o Bling. */
    public function direto(): bool
    {
        return (bool) config('services.amazon.direto');
    }

    /**
     * Estoque e preço só saem daqui no fluxo direto: no fluxo via Bling
     * quem cuida do anúncio é o Bling, e os dois brigariam.
     */
    private function garantirEnvioDeAnuncio(): void
    {
        $this->ensureConfigured();

        if (! $this->direto()) {
            throw new RuntimeException('Amazon ainda no fluxo via Bling: estoque e preço só vão daqui com AMAZON_DIRETO=true.');
        }
    }

    /**
     * Confirma o envio na Amazon pela SP-API: o pedido vira "Enviado" lá e
     * o comprador recebe o rastreio dos Correios. Única ponte automática
     * (o Bling não repassa rastreio pra Amazon).
     *
     * POST /orders/v0/orders/{id}/shipmentConfirmation (o confirmShipment
     * não tem data de remoção, só existe na v0). carrierCode "Correios" é
     * o valor exato da lista oficial da Amazon (CarrierCode do amzn-base.xsd,
     * conferido em 16/10/2026). Antes ia "Other" + carrierName: a Amazon
     * mostrava só o nome escrito, sem link de rastreio pro comprador e sem
     * contar como rastreio válido (VTR). Reenviar com o mesmo
     * packageReferenceId corrige um envio já confirmado.
     */
    public function confirmarEnvio(Order $order, string $rastreio, string $servico): void
    {
        $pedido = $this->buscarPedido((string) $order->external_order_id, 'FULFILLMENT');

        $itens = collect($pedido['orderItems'] ?? [])
            ->filter(fn ($item) => isset($item['orderItemId']))
            ->map(fn ($item) => ['orderItemId' => (string) $item['orderItemId'], 'quantity' => (int) ($item['quantityOrdered'] ?? 1)])
            ->values()
            ->all();

        if ($itens === []) {
            throw new RuntimeException("A Amazon não devolveu os itens do pedido {$order->external_order_id} pra confirmar o envio.");
        }

        $this->client->post("/orders/v0/orders/{$order->external_order_id}/shipmentConfirmation", [
            'marketplaceId' => config('services.amazon.marketplace_id'),
            'packageDetail' => [
                'packageReferenceId' => '1',
                'carrierCode' => 'Correios',
                'shippingMethod' => $servico,
                // Código de objeto limpo (AA123456789BR): é com ele que a
                // Amazon consulta os Correios pro comprador.
                'trackingNumber' => strtoupper(preg_replace('/\s+/', '', $rastreio)),
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
        $status = $this->buscarPedido((string) $order->external_order_id, 'FULFILLMENT')['fulfillment']['fulfillmentStatus'] ?? '';

        return self::mapFulfillmentStatus((string) $status) === Order::STATUS_SHIPPED;
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
