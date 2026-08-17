<?php

namespace Tests\Feature\Shopee;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\OrderChannelFee;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Modules\Marketplace\Support\OrderImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * BUG REAL 2026-08-17 ("as métricas não estão funcionando", achado
 * varrendo todo painel de métricas atrás do mesmo tipo de bug já corrigido
 * em 2026-08-15 pro faturamento): OrderChannelFee.gross_amount gravava
 * $data['total'] (subtotal + frete) no caminho automático (aqui e em
 * BackfillShopeeOrderFeesCommand), mas CashFlowController::updateSaleFee()
 * (mesma taxa, lançamento manual) já usava subtotal — OrderChannelFee::
 * netAmount() (gross_amount - fee_amount) vinha inflado pelo frete em todo
 * pedido com taxa vinda da API, a maioria dos pedidos reais.
 *
 * Usa importNormalized(dispatchShippingConfirmation: false) — mesmo ponto
 * de entrada da tela de teste de webhook (ver WebhookTestFixtures) — em vez
 * de import() com Http::fake(), pra não precisar simular o ship_order real
 * da Shopee (exige nota fiscal já enviada ao canal, fora do escopo deste
 * teste).
 */
class OrderChannelFeeGrossAmountTest extends TestCase
{
    use RefreshDatabase;

    public function test_channel_fee_gross_amount_excludes_shipping_cost(): void
    {
        // Item já mapeado pro catálogo local — evita cair em
        // autoImportProduct() (chamada real à API da Shopee, fora do
        // escopo deste teste).
        $product = Product::factory()->create();
        ProductChannelListing::create([
            'product_id' => $product->id,
            'channel' => MarketplaceAccount::CHANNEL_SHOPEE,
            'is_enabled' => true,
            'status' => ProductChannelListing::STATUS_PUBLISHED,
            'external_id' => '111',
            'last_synced_at' => now(),
        ]);

        $data = [
            'external_order_id' => 'SN-GROSS-1',
            'status' => Order::STATUS_PAID,
            'channel_status' => 'READY_TO_SHIP',
            'subtotal' => 44.99,
            'shipping_cost' => 13.25,
            'total' => 58.24,
            'marketplace_fee' => 6.75,
            'buyer_name' => 'Cliente Teste',
            'buyer_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X',
            'shipping_number' => 'S/N',
            'shipping_complement' => null,
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'external_shipment_id' => null,
            'items' => [
                ['external_id' => '111', 'quantity' => 1, 'unit_price' => 44.99],
            ],
        ];

        app(OrderImportService::class)->importNormalized(
            MarketplaceAccount::CHANNEL_SHOPEE,
            $data,
            dispatchShippingConfirmation: false,
        );

        $fee = OrderChannelFee::query()->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)->firstOrFail();

        // 44.99 (subtotal), nunca 58.24 (subtotal + frete).
        $this->assertSame(44.99, (float) $fee->gross_amount);
        $this->assertSame(6.75, (float) $fee->fee_amount);
        $this->assertSame(38.24, $fee->netAmount());
    }
}
