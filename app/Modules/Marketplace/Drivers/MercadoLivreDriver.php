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
        $buyerDocument = $this->orders->getBuyerDocument($externalOrderId);

        return [
            'external_order_id' => (string) $order->id,
            'status' => $this->mapOrderStatus($order->status),
            'subtotal' => round($itemsSubtotal, 2),
            'shipping_cost' => round(max(0, $order->total_amount - $itemsSubtotal), 2),
            'total' => round($order->total_amount, 2),
            'marketplace_fee' => round($marketplaceFee, 2),
            'buyer_name' => $buyerName,
            'buyer_document' => $buyerDocument,
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
            'placed_at' => \Illuminate\Support\Carbon::parse($order->date_created),
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
