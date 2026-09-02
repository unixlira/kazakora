<?php

namespace Tests\Feature\Marketplace;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\OrderImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Pedido explícito 2026-08-21: Shopee com pagamento ainda pendente
 * (AWAITING_PAYMENT) não deve virar Order nenhum — pedido "fantasma" que
 * pode nunca converter em venda de verdade ficava poluindo a lista de
 * Pedidos e a fila de separação/impressão. Ver OrderImportService::
 * importNormalized().
 */
class OrderImportServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Produto local já mapeado pro item ITEM-1 dos payloads abaixo. Sem
     * isso, criar o pedido cai no auto-import do canal (que exige conta
     * conectada de verdade) e o teste passa a medir a integração, não o
     * comportamento do import.
     */
    private function mapItemToLocalProduct(string $channel): void
    {
        $product = \App\Modules\Catalog\Models\Product::factory()->create(['stock' => 10]);

        \App\Modules\Marketplace\Models\ProductChannelListing::create([
            'product_id' => $product->id,
            'channel' => $channel,
            'is_enabled' => true,
            'status' => \App\Modules\Marketplace\Models\ProductChannelListing::STATUS_PUBLISHED,
            'external_id' => 'ITEM-1',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function normalizedData(array $overrides = []): array
    {
        return array_merge([
            'external_order_id' => 'SN-'.uniqid(),
            'status' => Order::STATUS_AWAITING_PAYMENT,
            'channel_status' => 'UNPAID',
            'buyer_name' => 'Cliente Teste',
            'buyer_document' => null,
            'buyer_phone' => null,
            'buyer_email' => null,
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X',
            'shipping_number' => '1',
            'shipping_complement' => null,
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 100,
            'shipping_cost' => 10,
            'total' => 110,
            'items' => [
                ['external_id' => 'ITEM-1', 'external_model_id' => null, 'unit_price' => 100, 'quantity' => 1],
            ],
        ], $overrides);
    }

    public function test_shopee_order_with_pending_payment_creates_no_order(): void
    {
        $data = $this->normalizedData();

        $result = app(OrderImportService::class)->importNormalized(MarketplaceAccount::CHANNEL_SHOPEE, $data, dispatchShippingConfirmation: false);

        $this->assertNull($result);
        $this->assertDatabaseCount('orders', 0);
    }

    /**
     * Assim que o mesmo pedido (mesmo external_order_id) chega de novo com
     * status pago — reentrega de webhook real da Shopee — o Order é criado
     * normalmente dessa vez (nenhum $existing pra achar, porque nunca
     * criamos nada na tentativa anterior).
     */
    public function test_shopee_order_creates_normally_once_payment_confirms(): void
    {
        Queue::fake();

        $externalOrderId = 'SN-'.uniqid();

        app(OrderImportService::class)->importNormalized(
            MarketplaceAccount::CHANNEL_SHOPEE,
            $this->normalizedData(['external_order_id' => $externalOrderId]),
            dispatchShippingConfirmation: false,
        );
        $this->assertDatabaseCount('orders', 0);

        $result = app(OrderImportService::class)->importNormalized(
            MarketplaceAccount::CHANNEL_SHOPEE,
            $this->normalizedData(['external_order_id' => $externalOrderId, 'status' => Order::STATUS_PAID, 'channel_status' => 'READY_TO_SHIP']),
            dispatchShippingConfirmation: false,
        );

        $this->assertNotNull($result);
        $this->assertSame(Order::STATUS_PAID, $result->status);
        $this->assertDatabaseCount('orders', 1);
    }

    /**
     * BUG REAL 2026-09-02 (pedido #1213, primeiro que chegou pelo webhook
     * do Bling — criado e cancelado no mesmo minuto): pedido que já NASCE
     * cancelado debitava estoque e nunca devolvia, porque
     * restoreStockIfNeeded() só roda na TRANSIÇÃO pra cancelado, e essa
     * transição nunca acontece. Eram 29 pedidos assim em 30 dias.
     */
    public function test_order_that_arrives_already_cancelled_never_debits_stock(): void
    {
        Queue::fake();

        $this->mapItemToLocalProduct(MarketplaceAccount::CHANNEL_TIKTOK_SHOP);
        $produto = \App\Modules\Catalog\Models\Product::first();
        $estoqueAntes = $produto->stock;

        $order = app(OrderImportService::class)->importNormalized(
            MarketplaceAccount::CHANNEL_TIKTOK_SHOP,
            $this->normalizedData(['status' => Order::STATUS_CANCELLED, 'channel_status' => 'CANCELLED']),
            dispatchShippingConfirmation: false,
        );

        $this->assertSame($estoqueAntes, $produto->fresh()->stock, 'Venda cancelada não pode consumir estoque.');
        $this->assertNotNull($order->stock_restored_at, 'Precisa ficar marcado como "sem estoque a devolver".');
    }

    /**
     * O contrário do teste acima: se o canal reabrir a venda, aí sim o
     * estoque tem que sair — é a marca stock_restored_at que syncStatus usa
     * pra saber disso.
     */
    public function test_order_born_cancelled_debits_stock_when_the_channel_reopens_the_sale(): void
    {
        Queue::fake();

        $this->mapItemToLocalProduct(MarketplaceAccount::CHANNEL_TIKTOK_SHOP);
        $produto = \App\Modules\Catalog\Models\Product::first();
        $estoqueAntes = $produto->stock;

        $service = app(OrderImportService::class);

        $order = $service->importNormalized(
            MarketplaceAccount::CHANNEL_TIKTOK_SHOP,
            $this->normalizedData(['status' => Order::STATUS_CANCELLED, 'channel_status' => 'CANCELLED']),
            dispatchShippingConfirmation: false,
        );

        $service->syncStatus($order, Order::STATUS_PAID);

        $this->assertSame($estoqueAntes - 1, $produto->fresh()->stock);
        $this->assertNull($order->fresh()->stock_restored_at);
    }

    /**
     * BUG REAL 2026-09-01 (pedido #894, Mercado Livre): pedido existente
     * estava com ZERO itens — a nota nunca sairia e o card da fila só
     * mostrava os itens do irmão de pacote. Reprocessar corrigia data,
     * totais e comprador, mas nunca os itens.
     */
    public function test_reimport_backfills_items_the_order_is_missing(): void
    {
        Queue::fake();
        $this->mapItemToLocalProduct(MarketplaceAccount::CHANNEL_MERCADO_LIVRE);

        $externalOrderId = 'SN-'.uniqid();
        $service = app(OrderImportService::class);

        $order = $service->importNormalized(
            MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            $this->normalizedData(['external_order_id' => $externalOrderId, 'status' => Order::STATUS_PAID]),
            dispatchShippingConfirmation: false,
        );

        // Simula o estado real encontrado em produção: pedido sem item nenhum.
        // (Pedido que JÁ tem item não é completado — ver o comentário em
        // reconcileMissingItems sobre o clone do #1216, que duplicou item.)
        $order->items()->delete();
        $this->assertSame(0, $order->items()->count());

        $service->importNormalized(
            MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            $this->normalizedData(['external_order_id' => $externalOrderId, 'status' => Order::STATUS_PAID]),
            dispatchShippingConfirmation: false,
        );

        $this->assertSame(1, $order->items()->count());
        $this->assertSame('ITEM-1', $order->items()->first()->external_item_id);

        // Idempotente: reprocessar de novo não duplica o item recuperado.
        $service->importNormalized(
            MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            $this->normalizedData(['external_order_id' => $externalOrderId, 'status' => Order::STATUS_PAID]),
            dispatchShippingConfirmation: false,
        );

        $this->assertSame(1, $order->items()->count());
    }

    /**
     * BUG REAL 2026-09-02 (pedido #1216): pra refazer uma nota com série
     * errada, o pedido foi CLONADO no Bling — e o clone reescreve o código
     * dos itens. Na reimportação, os códigos novos pareceram "itens que
     * faltavam" e viraram itens EXTRAS num pedido já completo: 4 no lugar
     * de 2, os novos sem produto vinculado, travando a emissão da nota e
     * arriscando o operador embalar o dobro.
     */
    public function test_order_that_already_has_items_is_never_completed_with_new_ones(): void
    {
        Queue::fake();

        $this->mapItemToLocalProduct(MarketplaceAccount::CHANNEL_TIKTOK_SHOP);
        $externalOrderId = 'SN-'.uniqid();
        $service = app(OrderImportService::class);

        $order = $service->importNormalized(
            MarketplaceAccount::CHANNEL_TIKTOK_SHOP,
            $this->normalizedData(['external_order_id' => $externalOrderId, 'status' => Order::STATUS_PAID]),
            dispatchShippingConfirmation: false,
        );

        $this->assertSame(1, $order->items()->count());

        // Mesma venda voltando com o item sob OUTRO código, como no clone.
        $service->importNormalized(
            MarketplaceAccount::CHANNEL_TIKTOK_SHOP,
            $this->normalizedData([
                'external_order_id' => $externalOrderId,
                'status' => Order::STATUS_PAID,
                'items' => [['external_id' => '3ITEM-1', 'external_model_id' => null, 'unit_price' => 100, 'quantity' => 1]],
            ]),
            dispatchShippingConfirmation: false,
        );

        $this->assertSame(1, $order->items()->count(), 'Pedido que já tem item não pode ganhar item novo na reimportação.');
    }

    /**
     * BUG REAL 2026-09-01 (relatado pelo usuário: "tem pedido em aberto que
     * ta no cancelado" — pedido #894, ML 2000018160810742, `paid` na API do
     * canal e `cancelled` aqui): cancelado era absorvente, então cada
     * consulta horária trazia "paid" do canal e era descartada — 20+
     * eventos "status paid ignorado" na linha do tempo e o pedido parado na
     * aba Cancelados com a venda de pé.
     */
    public function test_order_comes_back_from_cancelled_when_the_channel_says_it_is_paid_again(): void
    {
        Queue::fake();
        $this->mapItemToLocalProduct(MarketplaceAccount::CHANNEL_MERCADO_LIVRE);

        $externalOrderId = 'SN-'.uniqid();
        $service = app(OrderImportService::class);

        $order = $service->importNormalized(
            MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            $this->normalizedData(['external_order_id' => $externalOrderId, 'status' => Order::STATUS_PAID]),
            dispatchShippingConfirmation: false,
        );

        $service->syncStatus($order, Order::STATUS_CANCELLED);
        $this->assertSame(Order::STATUS_CANCELLED, $order->refresh()->status);

        $service->syncStatus($order, Order::STATUS_PAID);
        $this->assertSame(Order::STATUS_PAID, $order->refresh()->status);
        $this->assertNull($order->stock_restored_at, 'O estoque devolvido pelo cancelamento tem que ser debitado de novo.');
    }

    /**
     * A volta é só pra "pago" — o resto da trava de regressão continua de
     * pé: nada de um envio/conclusão antigos ressuscitarem um pedido que o
     * canal cancelou de verdade.
     */
    public function test_cancelled_order_still_ignores_shipped_and_awaiting_payment(): void
    {
        Queue::fake();
        $this->mapItemToLocalProduct(MarketplaceAccount::CHANNEL_MERCADO_LIVRE);

        $service = app(OrderImportService::class);

        $order = $service->importNormalized(
            MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            $this->normalizedData(['external_order_id' => 'SN-'.uniqid(), 'status' => Order::STATUS_PAID]),
            dispatchShippingConfirmation: false,
        );

        $service->syncStatus($order, Order::STATUS_CANCELLED);

        $service->syncStatus($order, Order::STATUS_SHIPPED);
        $this->assertSame(Order::STATUS_CANCELLED, $order->refresh()->status);

        $service->syncStatus($order, Order::STATUS_AWAITING_PAYMENT);
        $this->assertSame(Order::STATUS_CANCELLED, $order->refresh()->status);
    }

    /**
     * A trava é só pra Shopee — Mercado Livre/Amazon continuam criando o
     * pedido em qualquer status, comportamento não mudou pra eles (o status
     * "pendente" deles já significa outra coisa no fluxo real de cada um).
     */
    public function test_mercado_livre_order_with_pending_payment_still_creates_the_order(): void
    {
        $result = app(OrderImportService::class)->importNormalized(
            MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            $this->normalizedData(),
            dispatchShippingConfirmation: false,
        );

        $this->assertNotNull($result);
        $this->assertDatabaseCount('orders', 1);
    }

    /**
     * BUG REAL 2026-09-02 (pedido #1222): o nome do comprador chegou do
     * Mercado Livre com 61 caracteres (razão social duplicada pela própria
     * ML) e o schema da NF-e limita xNome a 60 — a nota nunca saiu. Até
     * aqui, resolveBuyerFieldUpdates() só trocava nome VAZIO ou MASCARADO,
     * então o pedido ficava travado pra sempre mesmo depois do canal
     * passar a devolver o nome certo.
     */
    public function test_reimport_replaces_a_buyer_name_that_is_too_long_for_the_invoice(): void
    {
        Queue::fake();
        $this->mapItemToLocalProduct(MarketplaceAccount::CHANNEL_MERCADO_LIVRE);

        $externalOrderId = 'SN-'.uniqid();
        $service = app(OrderImportService::class);
        $longName = '18.689.367 FABIO EDUARDO DOS S 18.689.367 FABIO EDUARDO DOS S';

        $order = $service->importNormalized(
            MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            $this->normalizedData([
                'external_order_id' => $externalOrderId,
                'status' => Order::STATUS_PAID,
                'buyer_name' => $longName,
            ]),
            dispatchShippingConfirmation: false,
        );

        $this->assertSame($longName, $order->refresh()->shipping_name);

        $service->importNormalized(
            MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            $this->normalizedData([
                'external_order_id' => $externalOrderId,
                'status' => Order::STATUS_PAID,
                'buyer_name' => '18.689.367 FABIO EDUARDO DOS SANTOS BARCELLOS',
            ]),
            dispatchShippingConfirmation: false,
        );

        $this->assertSame('18.689.367 FABIO EDUARDO DOS SANTOS BARCELLOS', $order->refresh()->shipping_name);
    }

    /**
     * A contrapartida do teste acima: nome que já cabe na nota nunca é
     * substituído por um reimport — a regra continua sendo "só conserta
     * vazio/mascarado/impossível de emitir", nunca sobrescrever dado bom.
     */
    public function test_reimport_never_overwrites_a_buyer_name_that_already_fits(): void
    {
        Queue::fake();
        $this->mapItemToLocalProduct(MarketplaceAccount::CHANNEL_MERCADO_LIVRE);

        $externalOrderId = 'SN-'.uniqid();
        $service = app(OrderImportService::class);

        $order = $service->importNormalized(
            MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            $this->normalizedData([
                'external_order_id' => $externalOrderId,
                'status' => Order::STATUS_PAID,
                'buyer_name' => 'Cliente Original',
            ]),
            dispatchShippingConfirmation: false,
        );

        $service->importNormalized(
            MarketplaceAccount::CHANNEL_MERCADO_LIVRE,
            $this->normalizedData([
                'external_order_id' => $externalOrderId,
                'status' => Order::STATUS_PAID,
                'buyer_name' => 'Outro Nome Qualquer',
            ]),
            dispatchShippingConfirmation: false,
        );

        $this->assertSame('Cliente Original', $order->refresh()->shipping_name);
    }
}
