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
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
            //
            // BUG REAL 2026-08-19 (importação da "Bike Spinning" da Shopee,
            // nome real do anúncio com 119 caracteres): ML rejeita
            // family_name com 400 "item.family_name.length_invalid" acima
            // de 60 caracteres — limite que nunca foi tratado aqui. Produto
            // com nome copiado de outro canal (Shopee permite título bem
            // mais longo) sempre batia nisso pra qualquer categoria que
            // exigisse family_name. Corta pro limite real da ML — melhor um
            // nome truncado do que a publicação inteira falhar.
            $retryDto = new ProductDTO(
                category_id: $categoryId,
                price: (float) $product->final_price,
                available_quantity: $product->stock,
                description: $product->description,
                pictures: $pictures,
                family_name: Str::limit($product->name, 60, ''),
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

    /**
     * Dados reais de 1 anúncio direto na API (GET /items/{id}) — usado por
     * autoImportProduct() quando um pedido chega pra um item ainda sem
     * produto local vinculado. Não lança: item sumido/erro de rede vira
     * null, quem chama decide o que fazer (mesmo contrato de
     * ShopeeDriver::fetchItemDetail()).
     *
     * `available_quantity` já é o estoque ATUAL no Mercado Livre — igual à
     * Shopee, já vem líquido desta própria venda (o ML debita na hora que o
     * pedido é pago), então quem chama precisa somar $quantitySold de volta
     * antes de gravar.
     *
     * `description` não vem no payload de /items/{id} (endpoint separado,
     * /items/{id}/description, que a Shopee não exige mas o ML sim) —
     * deixado de fora de propósito em vez de arriscar um valor errado; o
     * produto nasce sem descrição, igual já acontece hoje pra publicação
     * manual quando o vendedor não preenche nada.
     *
     * Sem `tax_info` equivalente ao da Shopee: a API do ML não expõe
     * NCM/CFOP/CSOSN por anúncio (confirmado — nenhum endpoint documentado
     * devolve isso), então o produto auto-importado do ML sempre nasce sem
     * ProductFiscalData, precisando de preenchimento manual antes da
     * primeira nota — mesmo estado que a Shopee já deixa quando o vendedor
     * não cadastrou tax_info lá.
     *
     * @return ?array{external_id: string, name: string, price: ?float, stock: ?int, sku: ?string}
     */
    public function fetchItemDetail(string $externalId): ?array
    {
        $this->ensureConfigured();

        try {
            $item = $this->client->get("items/{$externalId}");
        } catch (MercadoLivreException $exception) {
            Log::channel(config('mercadolivre.log_channel'))->warning('mercadolivre.item_detail.lookup_failed', [
                'external_id' => $externalId,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        if (empty($item['id'])) {
            return null;
        }

        // SKU real cadastrado no anúncio — confirmado ao vivo 2026-08-21:
        // NÃO vem no campo `seller_custom_field` (sempre null pra essa
        // conta), vem dentro de `attributes` com id="SELLER_SKU" (é
        // literalmente o campo "SKU" que aparece no painel do vendedor).
        // Usado por autoImportProduct() pra achar um produto local JÁ
        // existente com esse mesmo SKU antes de criar um produto novo.
        $sellerSkuAttribute = collect($item['attributes'] ?? [])->firstWhere('id', 'SELLER_SKU');

        return [
            'external_id' => (string) $item['id'],
            'name' => (string) ($item['title'] ?? ''),
            'price' => isset($item['price']) ? (float) $item['price'] : null,
            'stock' => isset($item['available_quantity']) ? (int) $item['available_quantity'] : null,
            'sku' => trim((string) ($sellerSkuAttribute['value_name'] ?? '')) ?: null,
        ];
    }

    /**
     * Cria um produto local do zero a partir de 1 item do Mercado Livre —
     * chamado por OrderImportService quando um pedido chega pra um anúncio
     * que nunca foi trazido pro catálogo (feito direto no painel do ML,
     * fora do Kazakora, ou publicado antes dessa integração existir).
     * Mesmo comportamento da Shopee (ver ShopeeDriver::autoImportProduct())
     * — entra sempre como rascunho (is_active=false, sem dados fiscais);
     * antes desse método existir, esse caso ficava com product_id null pra
     * sempre (pedido, etiqueta e NF-e sem SKU nenhum).
     *
     * Retorna null (sem lançar) se o ML não devolver o item — quem chama já
     * sabia lidar com "sem produto vinculado" antes disso existir.
     *
     * BUG REAL 2026-08-21: criava produto NOVO (SKU sintético "ML-{id}")
     * mesmo quando o produto certo já existia no catálogo local com o SKU
     * de verdade cadastrado (`SELLER_SKU`, ver fetchItemDetail()) — nunca
     * checava isso antes de criar. Produto duplicado com estoque
     * desconectado do produto real (a baixa de uma venda caía no
     * duplicado, nunca no produto de verdade). Casa pelo SKU real do ML
     * primeiro — só cai pro SKU sintético (cria produto novo mesmo) quando
     * o anúncio não tem SKU cadastrado ou quando não existe produto local
     * com esse SKU ainda.
     */
    public function autoImportProduct(string $externalId, int $quantitySold = 0, ?string $externalModelId = null): ?Product
    {
        $item = $this->fetchItemDetail($externalId);

        if (! $item || $item['name'] === '' || $item['price'] === null) {
            return null;
        }

        if ($item['sku'] && $existing = Product::where('sku', $item['sku'])->first()) {
            // BUG REAL 2026-08-22 (pedido Flex real perdido, achado
            // investigando ao vivo): product_channel_listings só permite 1
            // linha por (product_id, channel) — ver a migração da tabela.
            // Quando o produto já tinha uma linha pra este canal (apontando
            // pra OUTRO external_id — um segundo anúncio duplicado do
            // mesmo SKU no Mercado Livre, cenário real, não hipotético) o
            // firstOrCreate() abaixo não achava match pelo NOVO external_id
            // e tentava INSERIR uma segunda linha, estourando a constraint
            // única — exceção não tratada que derrubava o
            // ProcessMercadoLivreWebhook inteiro, então o PEDIDO nunca era
            // criado (não só a etiqueta/anúncio: a venda inteira sumia).
            // Sem linha nenhuma pra esse produto+canal ainda, cria normal;
            // já existindo (pra outro external_id), só loga pra revisão
            // manual (anúncio duplicado é problema de cadastro no ML, não
            // deveria travar o pedido) e segue — o que importa pro pedido é
            // o $product retornado, não a linha de listing em si.
            $listingExistsForOtherListing = ProductChannelListing::query()
                ->where('product_id', $existing->id)
                ->where('channel', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)
                ->where('external_id', '!=', $externalId)
                ->exists();

            if ($listingExistsForOtherListing) {
                Log::channel(config('mercadolivre.log_channel'))->warning('mercadolivre.order_import.duplicate_listing_for_product', [
                    'product_id' => $existing->id,
                    'sku' => $item['sku'],
                    'new_external_id' => $externalId,
                ]);
            } else {
                ProductChannelListing::query()->firstOrCreate(
                    ['channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE, 'external_id' => $externalId, 'external_model_id' => $externalModelId],
                    ['product_id' => $existing->id, 'is_enabled' => true, 'status' => ProductChannelListing::STATUS_PUBLISHED, 'last_synced_at' => now()],
                );
            }

            return $existing;
        }

        $sku = 'ML-'.$externalId;
        $slugBase = Str::slug($item['name']);
        $initialStock = $item['stock'] !== null ? max(0, $item['stock'] + $quantitySold) : 0;

        // Mesmo fix de corrida/soft-delete da Shopee (ver comentário em
        // ShopeeDriver::autoImportProduct(), BUG REAL 2026-08-17): nunca um
        // pré-check exists()-então-create() — reage à constraint de verdade
        // (sku OU slug) e regenera os dois com sufixo aleatório até vingar.
        $product = null;

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $suffix = $attempt === 1 ? '' : '-'.Str::random(4);

            try {
                $product = Product::create([
                    'sku' => $sku.$suffix,
                    'name' => $item['name'],
                    'slug' => $slugBase.$suffix,
                    'price' => $item['price'],
                    'stock' => $initialStock,
                    'is_active' => false,
                ]);

                break;
            } catch (QueryException $exception) {
                $isUniqueCollision = str_contains($exception->getMessage(), 'products_sku_unique')
                    || str_contains($exception->getMessage(), 'products_slug_unique');

                if ($attempt === 5 || ! $isUniqueCollision) {
                    throw $exception;
                }
            }
        }

        ProductChannelListing::query()->create([
            'product_id' => $product->id,
            'channel' => MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            'is_enabled' => true,
            'status' => ProductChannelListing::STATUS_PUBLISHED,
            'external_id' => $externalId,
            'external_model_id' => $externalModelId,
            'last_synced_at' => now(),
        ]);

        return $product;
    }

    public function importOrder(string $externalOrderId): array
    {
        $this->ensureConfigured();

        $order = $this->orders->getOrder($externalOrderId);
        $address = $this->resolveShippingAddress($order->shipping);

        $itemsSubtotal = 0.0;
        $marketplaceFee = 0.0;
        $items = [];

        foreach ($order->order_items as $item) {
            $externalId = (string) ($item['item']['id'] ?? '');
            $quantity = (int) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['unit_price'] ?? 0);

            if ($externalId === '' || $quantity < 1) {
                continue;
            }

            $itemsSubtotal += $unitPrice * $quantity;
            // sale_fee é a comissão REAL cobrada pelo Mercado Livre nesse
            // item (confirmado no payload de um pedido real, 2026-08-02 —
            // não é estimativa, é o valor de fato descontado na venda), por
            // unidade — mesma convenção de unit_price, por isso ×quantity.
            $marketplaceFee += (float) ($item['sale_fee'] ?? 0) * $quantity;
            // external_name (item.title) é o nome real do anúncio no
            // Mercado Livre — usado como fallback pelo OrderImportService
            // quando o item ainda não tem produto local mapeado (achado
            // real 2026-08-06: sem isso, um item não mapeado importava
            // como "Item {id} (sem produto local mapeado)" mesmo tendo o
            // nome disponível no payload o tempo todo).
            $items[] = [
                'external_id' => $externalId,
                'external_name' => $item['item']['title'] ?? null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
            ];
        }

        $buyer = $order->buyer;
        $buyerName = trim(($buyer['first_name'] ?? '').' '.($buyer['last_name'] ?? '')) ?: ($buyer['nickname'] ?? 'Comprador Mercado Livre');

        // O payload padrão do pedido não traz CPF (só nome/endereço,
        // confirmado antes) — precisa desse endpoint dedicado, que a ML
        // mantém especificamente pra emissão de nota fiscal. Sem isso a
        // NF-e não tem como identificar o destinatário (CPF é obrigatório
        // no modelo 55, real bug real encontrado 2026-08-02 travando o
        // pipeline inteiro venda→nota→envio→etiqueta pra pedido de canal).
        $buyerBilling = $this->orders->getBuyerBillingData($externalOrderId);

        return [
            'external_order_id' => (string) $order->id,
            'status' => $this->mapOrderStatus($order->status),
            'subtotal' => round($itemsSubtotal, 2),
            'shipping_cost' => round(max(0, $order->total_amount - $itemsSubtotal), 2),
            'total' => round($order->total_amount, 2),
            'marketplace_fee' => round($marketplaceFee, 2),
            'buyer_name' => $buyerName,
            // Pedido explícito 2026-09-01 ("colocar destinatario e o nome
            // do usuario entre parenteses"): o KoraSync mostra
            // "{destinatário} ({apelido})" — ver
            // DashboardAgentController::mapQueueOrder(). Os dois são só
            // exibição: buyer_name (acima) continua sendo o nome que casa
            // com o CPF na NF-e.
            'recipient_name' => $address['recipient'] ?? null,
            'buyer_nickname' => $buyer['nickname'] ?? null,
            'buyer_document' => $buyerBilling['document'],
            'buyer_state_registration' => $buyerBilling['state_registration'],
            'buyer_taxpayer_type' => $buyerBilling['taxpayer_type'],
            // 'email' aqui é o relay mascarado do próprio Mercado Livre
            // (algo como xxx@mail.mercadolivre.com), não o e-mail real do
            // comprador — o ML não expõe o e-mail verdadeiro pra vendedor
            // por privacidade. Guardado do mesmo jeito porque ainda serve
            // pra contato via esse relay.
            'buyer_email' => $buyer['email'] ?? null,
            // buyer.phone.number vem vazio na prática pra pedido com
            // Mercado Envios 2 (LGPD) — ver comentário em
            // resolveShippingAddress() sobre o fallback receiver_phone,
            // que só existe (e mesmo assim ofuscado) pra Mercado Envios 1.
            'buyer_phone' => $buyer['phone']['number'] ?? $address['phone'] ?? null,
            // alternative_phone é o único campo que a ML separa do telefone
            // principal — preenchido só quando o comprador informa um
            // segundo número; não é literalmente "o WhatsApp dele", é o
            // melhor proxy que a API oferece pra um segundo contato.
            'buyer_whatsapp' => $buyer['alternative_phone']['number'] ?? null,
            'shipping_zip' => $address['zip'],
            'shipping_street' => $address['street'],
            'shipping_number' => $address['number'],
            'shipping_complement' => $address['complement'],
            'shipping_neighborhood' => $address['neighborhood'],
            'shipping_city' => $address['city'],
            'shipping_state' => $address['state'],
            'external_shipment_id' => isset($order->shipping['id']) ? (string) $order->shipping['id'] : null,
            // Data real da venda no Mercado Livre — sem isso, OrderImportService
            // usa now() como created_at, o que é ok pra webhook em tempo real
            // (chega em segundos) mas fica errado pro backfill (achado real
            // 2026-08-06: pedidos de meses atrás sendo importados hoje
            // ficavam marcados como "vendidos hoje", inflando os cards de
            // faturamento do dia/mês).
            //
            // ->setTimezone(): date_created vem com offset fixo da própria
            // API do ML (ex.: "...-04:00"), não o nosso app.timezone
            // (America/Sao_Paulo, -03:00). Carbon::parse() de uma string com
            // offset explícito MANTÉM esse offset no objeto — não converte
            // pro timezone padrão do PHP. Sem normalizar aqui, o forceFill em
            // OrderImportService grava os dígitos de -04:00 direto no banco
            // como se já fossem hora de São Paulo (Eloquent formata a data
            // no timezone que o objeto Carbon já tem, nunca converte
            // sozinho) — created_at fica 1h ATRASADO em relação à hora real
            // de SP. Achado real 2026-08-13: pedido feito de madrugada
            // (00h-01h de SP) ficava gravado ainda no dia anterior, e por
            // tabela sumia da fila "só hoje" do KoraSync
            // (DashboardAgentController::queue()) em vez de aparecer nela.
            'placed_at' => \Illuminate\Support\Carbon::parse($order->date_created)->setTimezone(config('app.timezone')),
            'items' => $items,
        ];
    }

    /**
     * Envia o XML da NF-e assinada pro Mercado Livre. Endpoint documentado
     * pra Flex/Turbo/ME1/Drop Off — a doc em inglês desse mesmo endpoint diz
     * "não disponível pro Brasil" enquanto a doc em português o documenta
     * especificamente pra vendedores brasileiros.
     *
     * BUG REAL 2026-08-12 (pedido #243): usar external_order_id direto como
     * pack_id só funciona quando o pedido NÃO pertence a um pack de
     * verdade — confirmado ao vivo que o Mercado Livre rejeita com 400
     * "order_belong_pack" quando o pedido tem `pack_id` próprio (comprador
     * levou mais de um pedido junto no mesmo envio, tag `pack_order` no
     * order). Busca o order real pra pegar o `pack_id` de verdade e só cai
     * pro external_order_id quando o pedido não tem pack (pack_id
     * ausente/null — a maioria dos pedidos avulsos).
     */
    public function submitInvoice(Order $order, Invoice $invoice): array
    {
        $this->ensureConfigured();

        if (! $invoice->xml_path) {
            throw new RuntimeException('Nota fiscal sem XML disponível para envio.');
        }

        $xml = Storage::disk('local')->get($invoice->xml_path);
        $filename = "nfe-{$invoice->chave_acesso}.xml";

        $mlOrder = $this->client->get("orders/{$order->external_order_id}");
        $packId = $mlOrder['pack_id'] ?? null;
        $shippingId = $mlOrder['shipping']['id'] ?? null;

        try {
            if ($shippingId) {
                $shipment = $this->client->get("shipments/{$shippingId}");
                $logisticType = $shipment['logistic_type'] ?? ($shipment['logistic']['type'] ?? null);

                // Para Mercado Livre Brasil, envios que ficam em
                // substatus=invoice_pending (drop_off, xd_drop_off,
                // cross_docking, xd_same_day) NÃO aceitam o upload pelo
                // endpoint genérico de pack. A documentação "Importar Nota
                // Fiscal" manda importar o XML direto no shipment:
                // POST /shipments/{shipment_id}/invoice_data?siteId=MLB.
                // Usar /packs/{id}/fiscal_documents nesses casos retorna
                // 403 "Access denied, you must use the biller of
                // MercadoLibre" e deixa a etiqueta travada.
                if (in_array($logisticType, ['drop_off', 'xd_drop_off', 'cross_docking', 'xd_same_day'], true)) {
                    $response = $this->client->postXml(
                        "shipments/{$shippingId}/invoice_data",
                        $xml,
                        ['siteId' => config('mercadolivre.site_id', 'MLB')],
                    );

                    return [
                        'status' => 'sent',
                        'external_reference' => (string) $shippingId,
                        'response' => $response + ['endpoint' => 'shipment_invoice_data', 'logistic_type' => $logisticType],
                    ];
                }
            }

            $response = $this->client->postMultipart(
                'packs/'.($packId ?: $order->external_order_id).'/fiscal_documents',
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
     * como a documentação oficial do ML mostra (`logistic.type`/`logistic.mode`)
     * — mantém fallback pro formato aninhado da doc caso o topo não venha
     * preenchido em algum site/categoria.
     */
    public function confirmShipping(Order $order): array
    {
        $this->ensureConfigured();

        $shipmentId = $this->resolveShipmentId($order);
        $shipment = $this->shipments->getShipment($shipmentId);

        $logisticType = $shipment['logistic_type'] ?? $shipment['logistic']['type'] ?? 'unknown';

        return [
            'external_shipment_id' => (string) ($shipment['id'] ?? $shipmentId),
            'tracking_code' => $shipment['tracking_number'] ?? null,
            'shipping_method' => $logisticType,
            'status' => $shipment['status'] ?? 'unknown',
            'scheduled_for' => $this->extractScheduledFor($shipment),
        ];
    }

    /**
     * BUG REAL 2026-08-14 (pedido #278, achado ao vivo): envio
     * `logistic_type=xd_drop_off` (Coleta/Places) pode vir com
     * `shipping_option.buffering.date` no futuro — o Mercado Livre decidiu
     * de propósito só liberar a etiqueta perto dessa data (não é a etiqueta
     * "travada", é uma venda agendada de verdade). Sem isso, CheckShipmentLabelJob
     * achava que era um problema e martelava a API por até 4h antes de
     * desistir. Só conta como "agendado" se a data vier e ainda estiver no
     * futuro — um buffering já vencido não é um agendamento pendente, é só
     * metadado velho do canal.
     */
    private function extractScheduledFor(array $shipment): ?\Illuminate\Support\Carbon
    {
        $bufferingDate = $shipment['shipping_option']['buffering']['date'] ?? null;

        if (! $bufferingDate) {
            return null;
        }

        $date = \Illuminate\Support\Carbon::parse($bufferingDate);

        if ($date->isFuture()) {
            return $date;
        }

        // Mercado Livre pode continuar devolvendo pending/buffered mesmo
        // quando `buffering.date` vem como meia-noite UTC e, em São Paulo,
        // já parece passado. Nesse caso a data ainda é operacionalmente
        // relevante: mantém o DIA do agendamento para o KoraSync mostrar
        // "sem etiqueta"/agendado e para os retries não tratarem como pedido
        // normal sem agenda.
        if (($shipment['status'] ?? null) === 'pending' && ($shipment['substatus'] ?? null) === 'buffered') {
            $datePart = substr((string) $bufferingDate, 0, 10);

            return \Illuminate\Support\Carbon::createFromFormat('Y-m-d H:i:s', "{$datePart} 00:00:00", config('app.timezone'));
        }

        return null;
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

        // BUG REAL 2026-08-10, achado ao tentar reprocessar o pedido #214
        // manualmente logo depois do fix acima: o próprio Mercado Livre
        // vira o substatus de "ready_to_print" pra "printed" assim que
        // shipment_labels é chamado UMA vez, mesmo que o download tenha
        // sido só uma verificação/reprocessamento (nunca voltamos a "não
        // pronta"). Exigir estritamente "ready_to_print" travava qualquer
        // reimpressão/reprocessamento depois da primeira tentativa — a
        // etiqueta continua válida e disponível em "printed" também.
        if (($shipment['status'] ?? null) !== 'ready_to_ship' || ! in_array($shipment['substatus'] ?? null, ['ready_to_print', 'printed'], true)) {
            return ['ready' => false, 'contents' => null, 'content_type' => null];
        }

        // BUG REAL 2026-08-10 (etiqueta não saía na impressora térmica,
        // "impressora parada" — nada acontecia, sem erro nenhum registrado):
        // response_type=pdf devolve a folha A4 padrão do Mercado Livre (2
        // páginas — aviso "declare sua venda" + etiqueta em tamanho de
        // corte pra impressora comum), nunca pensada pra bobina térmica
        // 4x6". A KazaKora-Printer aceitava o job (por isso "printed" sem
        // erro no servidor) mas não fazia nada com um PDF em A4. Trocado
        // pra response_type=zpl2 (confirmado na doc oficial do ML: devolve
        // um ZIP com um PDF da PLP + um TXT com o ZPL de verdade pra
        // impressora Zebra/térmica) — mesmo formato que a Shopee já
        // devolve, reaproveita o mesmo pipeline de unzip+conversão em
        // LabelFetchService.
        $label = $this->client->getBinary('shipment_labels', [
            'shipment_ids' => $shipmentId,
            'response_type' => 'zpl2',
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
     * `receiver_phone` (confirmado contra a doc oficial de Shipments) só
     * existe pra pedido com Mercado Envios 1 (modo antigo) e ainda assim
     * vem ofuscado — não é um substituto de verdade pro telefone real,
     * mas serve de fallback quando `buyer.phone.number` (o campo que
     * `importOrder()` usa como fonte principal) vem vazio, o que é o caso
     * comum hoje (Mercado Envios 2, que não expõe telefone nenhum no
     * objeto do comprador por LGPD).
     *
     * @param  array<string, mixed>  $shipping
     * @return array{zip: string, street: string, number: string, complement: ?string, neighborhood: string, city: string, state: string, phone: ?string}
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
            'phone' => null,
            'recipient' => null,
        ];

        $shipmentId = $shipping['id'] ?? null;

        if (! $shipmentId) {
            return $fallback;
        }

        $shipment = $this->shipments->getShipment((string) $shipmentId);
        $receiver = $shipment['receiver_address'] ?? [];

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
            // Campo pode vir tanto no nível do shipment quanto dentro de
            // receiver_address dependendo do formato de resposta — a doc
            // oficial não é explícita sobre isso, então tenta os dois.
            'phone' => $shipment['receiver_phone'] ?? $receiver['receiver_phone'] ?? null,
            // Quem RECEBE o pacote, direto da etiqueta — nem sempre é o
            // titular da conta (presente enviado pra outra pessoa, compra
            // entregue no trabalho etc.). Só exibição; o nome que vai na
            // NF-e continua sendo o do comprador (ver importOrder()).
            'recipient' => $receiver['receiver_name'] ?? null,
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
