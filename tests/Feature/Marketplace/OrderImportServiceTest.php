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
}
