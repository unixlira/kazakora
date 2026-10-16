<?php

namespace Tests;

use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * SP-API conectada respondendo o pedido com este OrderStatus. Pedido da
     * Amazon só é expedido com o pagamento confirmado lá (PagamentoAmazon).
     * Chamar antes dos outros Http::fake() do teste: o primeiro que casa vence.
     */
    protected function amazonComPedidoNoStatus(string $orderStatus = 'Unshipped'): void
    {
        config(['services.amazon.sp_api_base_url' => 'https://sellingpartnerapi.test']);

        MarketplaceAccount::query()->updateOrCreate(
            ['channel' => MarketplaceAccount::CHANNEL_AMAZON],
            [
                'status' => MarketplaceAccount::STATUS_CONNECTED,
                'access_token' => 'lwa-token',
                'refresh_token' => 'lwa-refresh',
                'token_expires_at' => now()->addDays(30),
            ],
        );

        Http::fake(['sellingpartnerapi.test/orders/v0/orders/*' => Http::response(['payload' => ['OrderStatus' => $orderStatus]])]);
    }
}
