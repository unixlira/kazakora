<?php

namespace App\Modules\Marketplace\Drivers;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Services\Bling\BlingOrderService;
use App\Services\Bling\Exceptions\BlingException;
use RuntimeException;

/**
 * TikTok Shop — API direta docs: https://partner.tiktokshop.com (Partner
 * Center, aprovação de parceiro exigida pra vendedor BR, ainda pendente).
 *
 * IMPORTAÇÃO DE PEDIDO (pedido explícito 2026-08-31): em vez de esperar
 * essa aprovação, os pedidos chegam via BLING — o vendedor conecta o TikTok
 * Shop uma vez DENTRO do painel do Bling (ver ajuda.bling.com.br
 * "Autenticação com o TikTok Shop"/"Configuração do TikTok Shop"), e este
 * driver consulta os pedidos já sincronizados lá (BlingOrderService),
 * filtrados pela loja configurada como TikTok Shop. `origin` do Order
 * continua sendo CHANNEL_TIKTOK_SHOP (é uma venda do TikTok Shop de
 * verdade) — Bling é só o cano, não aparece em lugar nenhum pro resto do
 * sistema (KoraSync, NF-e, etc. não sabem nem precisam saber que passou
 * pelo Bling).
 *
 * O restante (publicar produto, atualizar estoque, enviar NF-e, confirmar
 * envio, buscar etiqueta) continua não implementado — são funções que
 * exigiriam ou a API direta do TikTok Shop (aprovação pendente) ou
 * endpoints do Bling específicos de nota fiscal/expedição que não foram
 * verificados contra uma conta real nesta sessão. Documentado como TODO,
 * não como "funciona" — não é regressão: já não funcionava antes.
 */
class TikTokShopDriver extends AbstractMarketplaceDriver
{
    public function __construct(private readonly BlingOrderService $blingOrders) {}

    public function channel(): string
    {
        return MarketplaceAccount::CHANNEL_TIKTOK_SHOP;
    }

    public function publishProduct(Product $product, ProductChannelListing $listing): string
    {
        $this->ensureConfigured();

        // TODO: upload $product->images via /product/202309/images/upload,
        // then call /product/202309/products with the returned image ids
        // and the category-specific attributes from $listing->attributes.
        throw new \RuntimeException('Integração com TikTok Shop ainda não implementada.');
    }

    public function updateStock(Product $product, ProductChannelListing $listing): void
    {
        $this->ensureConfigured();

        // TODO: call /product/202309/inventory/update with $product->stock.
        throw new \RuntimeException('Integração com TikTok Shop ainda não implementada.');
    }

    public function unpublishProduct(ProductChannelListing $listing): void
    {
        $this->ensureConfigured();

        // TODO: call /product/202309/products/deactivate.
        throw new \RuntimeException('Integração com TikTok Shop ainda não implementada.');
    }

    /**
     * Achado real 2026-08-19 na Shopee/ML (comentário replicado aqui de
     * propósito — mesma classe de bug seria fácil de repetir): item sem
     * produto local mapeado não pode virar "sem produto" pro resto da
     * vida só porque não achou de primeira — mas aqui também não
     * inventamos um produto novo sem dado real (nome/preço) confiável;
     * a API do Bling de busca de produto por SKU não foi verificada
     * contra uma conta real nesta sessão, então autoImportProduct() só
     * resolve o caso em que o SKU já existe no catálogo local — criar um
     * produto do zero fica pra uma sessão com acesso real ao Bling pra
     * confirmar o endpoint certo, em vez de arriscar um chute.
     */
    public function autoImportProduct(string $externalId, int $quantitySold = 0, ?string $externalModelId = null): ?Product
    {
        // $externalId aqui É o SKU (ver importOrder() — usamos itens[].codigo
        // do Bling direto como external_id, não um id interno do Bling),
        // então o match é direto contra o catálogo local.
        $product = Product::where('sku', $externalId)->first();

        if (! $product) {
            return null;
        }

        ProductChannelListing::query()->firstOrCreate(
            ['channel' => MarketplaceAccount::CHANNEL_TIKTOK_SHOP, 'external_id' => $externalId],
            ['product_id' => $product->id, 'is_enabled' => true, 'status' => ProductChannelListing::STATUS_PUBLISHED, 'last_synced_at' => now()],
        );

        return $product;
    }

    /**
     * $externalOrderId aqui é o número do pedido no TikTok Shop (o que o
     * Bling chama de `numeroLoja`), não um id interno do Bling nem do
     * TikTok — é esse número que fica salvo em orders.external_order_id,
     * pra bater com o que aparece de verdade pro vendedor no painel do
     * TikTok Shop.
     */
    public function importOrder(string $externalOrderId): array
    {
        $order = $this->blingOrders->findByOrderNumber($externalOrderId);

        if (! $order) {
            throw new RuntimeException("Pedido {$externalOrderId} não encontrado na loja do TikTok Shop conectada ao Bling.");
        }

        $itemsSubtotal = 0.0;
        $items = [];

        foreach ($order['itens'] ?? [] as $item) {
            $quantity = (int) ($item['quantidade'] ?? 0);
            $unitPrice = (float) ($item['valor'] ?? 0);
            // Prioriza o código/SKU real do produto — é ele que casa direto
            // com Product::sku (ver autoImportProduct() acima). Cai pro id
            // interno do Bling só se o item não tiver código cadastrado lá.
            $externalId = $item['codigo'] ?? (isset($item['produto']['id']) ? 'BLING-'.$item['produto']['id'] : null);

            if ($externalId === null || $quantity < 1) {
                continue;
            }

            $itemsSubtotal += $unitPrice * $quantity;

            $items[] = [
                'external_id' => (string) $externalId,
                'external_name' => $item['descricao'] ?? null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
            ];
        }

        $buyerName = $order['contato']['nome'] ?? 'Comprador TikTok Shop';
        $buyerDocument = isset($order['contato']['numeroDocumento'])
            ? preg_replace('/\D/', '', (string) $order['contato']['numeroDocumento'])
            : null;

        // Telefone/e-mail não vêm no pedido em si (só id/nome/documento do
        // contato) — precisa da chamada complementar, mesmo padrão já
        // usado pra CPF/CNPJ do Mercado Livre/Shopee. Nunca derruba a
        // importação se falhar (mesma cautela dos outros drivers): sem
        // telefone/e-mail o pedido ainda é importável, só com esses campos
        // vazios.
        $contact = null;

        if (isset($order['contato']['id'])) {
            try {
                $contact = $this->blingOrders->findContact((int) $order['contato']['id']);
            } catch (BlingException) {
                $contact = null;
            }
        }

        $address = $order['transporte']['etiqueta'] ?? [];
        $trackingCode = $order['transporte']['volumes'][0]['codigoRastreamento'] ?? null;

        return [
            'external_order_id' => (string) ($order['numeroLoja'] ?? $externalOrderId),
            'status' => $this->mapOrderStatus($order),
            'subtotal' => round($itemsSubtotal, 2),
            'shipping_cost' => round((float) ($order['transporte']['frete'] ?? 0), 2),
            'total' => round((float) ($order['total'] ?? $itemsSubtotal), 2),
            'buyer_name' => $buyerName,
            'buyer_document' => $buyerDocument,
            'buyer_phone' => $contact['celular'] ?? $contact['telefone'] ?? null,
            'buyer_email' => $contact['email'] ?? null,
            'buyer_whatsapp' => null,
            'shipping_zip' => $address['cep'] ?? '00000000',
            'shipping_street' => $address['endereco'] ?? 'Não informado',
            'shipping_number' => $address['numero'] ?? 'S/N',
            'shipping_complement' => $address['complemento'] ?? null,
            'shipping_neighborhood' => $address['bairro'] ?? 'Não informado',
            'shipping_city' => $address['municipio'] ?? 'Não informado',
            'shipping_state' => $address['uf'] ?? 'SP',
            'external_shipment_id' => $trackingCode,
            // Bling só dá a DATA (sem hora) do pedido — mesmo assim melhor
            // que now() pra backfill (ver o mesmo argumento em
            // MercadoLivreDriver::importOrder()/ShopeeDriver::importOrder()
            // sobre created_at errado inflar métricas do dia errado).
            'placed_at' => isset($order['data']) ? \Illuminate\Support\Carbon::parse($order['data'], config('app.timezone')) : null,
            'items' => $items,
        ];
    }

    /**
     * Situações do Bling são personalizáveis por conta (ver
     * BlingOrderService::situacaoName()) — resolve pelo NOME real em vez
     * de um id numérico fixo, que poderia variar de conta pra conta.
     * Default seguro: PAID — pelo momento em que um pedido de marketplace
     * chega até aqui (já sincronizado do TikTok Shop pro Bling), a venda
     * quase sempre já foi paga do lado do canal; só rebaixa pra CANCELLED
     * quando o nome da situação deixa isso explícito.
     */
    private function mapOrderStatus(array $order): string
    {
        $situacaoId = $order['situacao']['id'] ?? null;

        if ($situacaoId === null) {
            return Order::STATUS_PAID;
        }

        try {
            $nome = mb_strtolower($this->blingOrders->situacaoName((int) $situacaoId) ?? '');
        } catch (BlingException) {
            return Order::STATUS_PAID;
        }

        return str_contains($nome, 'cancel') ? Order::STATUS_CANCELLED : Order::STATUS_PAID;
    }

    /**
     * Confirmado (2026-08-01): TikTok Shop BR exige NF-e (XML, <10MB) via
     * "Gerenciar Pedidos" antes do pedido poder virar "Pronto para envio" —
     * existe uma página de doc dedicada "BR market - Updated API workflow
     * to support Order, Invoice and Warehouse", mas o conteúdo fica atrás de
     * login de parceiro aprovado — endpoint exato não confirmado ainda.
     */
    public function submitInvoice(Order $order, Invoice $invoice): array
    {
        $this->ensureConfigured();

        // TODO: endpoint exato só visível com credencial de parceiro
        // aprovada — ver "BR market - Order, Invoice and Warehouse" no
        // Partner Center quando a conta existir.
        throw new \RuntimeException('Integração com TikTok Shop ainda não implementada.');
    }

    public function confirmShipping(Order $order): array
    {
        $this->ensureConfigured();

        // TODO: Fulfillment API, fluxo geral (não confirmado em detalhe):
        // Get Shipping Provider → Create Package → Ship Package.
        throw new \RuntimeException('Integração com TikTok Shop ainda não implementada.');
    }

    public function fetchLabel(Order $order): array
    {
        $this->ensureConfigured();

        // TODO: "Get Package Shipping Document", pós Ship Package.
        throw new \RuntimeException('Integração com TikTok Shop ainda não implementada.');
    }
}
