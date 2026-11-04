<?php

namespace Tests\Feature\Amazon;

use App\Jobs\ProcessBlingOrderWebhook;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Marketplace\Jobs\ConfirmChannelShippingJob;
use App\Modules\Marketplace\Models\ChannelWebhookLog;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\OrderImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * BUG REAL 2026-10-03 (#2730, #2748, #2749): pedido da Amazon que chega
 * pelo Bling virava "pago" mesmo com o pagamento ainda pendente na Amazon,
 * e ganhava NF-e, etiqueta e impressão antes de a Amazon cancelar. Com a
 * SP-API conectada, quem diz se está pago é a Amazon.
 */
class AmazonPagamentoPendenteTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://sellingpartnerapi.test';

    private const LOJA_AMAZON = 206308488;

    private const NUMERO_AMAZON = '701-1167157-6796210';

    private const BLING_ID = 22334455667;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.amazon.sp_api_base_url' => self::BASE,
            'services.amazon.marketplace_id' => 'A2Q3Y263D00KWC',
            'services.bling.api_base_url' => 'https://api.bling.test/Api/v3',
            'services.bling.amazon_loja_id' => self::LOJA_AMAZON,
            'services.bling.invoice_issuer_channels' => [Order::ORIGIN_TIKTOK_SHOP],
        ]);

        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_BLING, 'status' => MarketplaceAccount::STATUS_CONNECTED,
            'access_token' => 't', 'refresh_token' => 'r', 'token_expires_at' => now()->addHour(),
        ]);
        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_AMAZON, 'status' => MarketplaceAccount::STATUS_CONNECTED,
            'access_token' => 'lwa-token', 'refresh_token' => 'rt', 'token_expires_at' => now()->addHour(),
        ]);

        Product::factory()->create(['sku' => 'KZ-POWERBANK-001', 'stock' => 5]);
        Queue::fake([GenerateInvoiceJob::class, ConfirmChannelShippingJob::class]);
    }

    /**
     * @param  array<int, mixed>|mixed  $spApi  resposta(s) do getOrder da SP-API, em sequência
     */
    private function fake(mixed $spApi): void
    {
        $sequencia = Http::sequence();

        foreach (is_array($spApi) && array_is_list($spApi) ? $spApi : [$spApi] as $resposta) {
            is_string($resposta)
                ? $sequencia->push(['payload' => ['AmazonOrderId' => self::NUMERO_AMAZON, 'OrderStatus' => $resposta]])
                : $sequencia->pushResponse($resposta);
        }

        Http::fake([
            self::BASE.'/orders/v0/orders/'.self::NUMERO_AMAZON => $sequencia,
            '*/pedidos/vendas/'.self::BLING_ID => Http::response(['data' => [
                'id' => self::BLING_ID,
                'numeroLoja' => self::NUMERO_AMAZON,
                'data' => '2026-10-16',
                'total' => 73.92,
                'loja' => ['id' => self::LOJA_AMAZON],
                'situacao' => ['id' => 6],
                'contato' => ['id' => 1111, 'nome' => 'Maria Compradora', 'numeroDocumento' => '123.456.789-09'],
                'desconto' => ['valor' => 0],
                'itens' => [['codigo' => 'KZ-POWERBANK-001', 'descricao' => 'Power bank', 'quantidade' => 1, 'valor' => 73.92]],
                'transporte' => [
                    'frete' => 0,
                    'etiqueta' => [
                        'endereco' => 'Rua das Flores', 'numero' => '10', 'bairro' => 'Centro',
                        'municipio' => 'Campinas', 'uf' => 'SP', 'cep' => '13010-000',
                    ],
                    'volumes' => [['codigoRastreamento' => '']],
                ],
            ]]),
            '*/pedidos/vendas?*' => Http::response(['data' => []]),
            '*/situacoes/6' => Http::response(['data' => ['id' => 6, 'nome' => 'Em aberto']]),
            '*/contatos/1111' => Http::response(['data' => ['celular' => '19999999999']]),
        ]);
    }

    private function webhook(string $evento = 'order.created'): ?Order
    {
        $payload = [
            'eventId' => 'evento-'.$evento,
            'event' => $evento,
            'data' => ['id' => self::BLING_ID, 'numeroLoja' => self::NUMERO_AMAZON, 'loja' => ['id' => self::LOJA_AMAZON], 'situacao' => ['id' => 6]],
        ];
        $log = ChannelWebhookLog::create([
            'channel' => MarketplaceAccount::CHANNEL_AMAZON, 'event_type' => $evento, 'payload' => $payload,
            'headers' => [], 'signature_valid' => true, 'status' => ChannelWebhookLog::STATUS_RECEIVED,
        ]);

        dispatch_sync(new ProcessBlingOrderWebhook($payload, $log->id));

        return Order::query()->where('origin', Order::ORIGIN_AMAZON)->where('external_order_id', self::NUMERO_AMAZON)->first();
    }

    public function test_pending_payment_on_amazon_holds_the_order_without_invoice_nor_label(): void
    {
        $this->fake('Pending');

        $order = $this->webhook();

        $this->assertNotNull($order);
        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->status);
        Queue::assertNotPushed(GenerateInvoiceJob::class);
        Queue::assertNotPushed(ConfirmChannelShippingJob::class);
    }

    public function test_pending_availability_is_also_held(): void
    {
        $this->fake('PendingAvailability');

        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $this->webhook()->status);
        Queue::assertNotPushed(ConfirmChannelShippingJob::class);
    }

    public function test_order_is_released_when_amazon_confirms_the_payment(): void
    {
        $this->fake(['Pending', 'Unshipped']);

        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $this->webhook()->status);
        Queue::assertNotPushed(ConfirmChannelShippingJob::class);

        $this->assertSame(Order::STATUS_PAID, $this->webhook('order.updated')->status);
        Queue::assertPushed(GenerateInvoiceJob::class);
        Queue::assertPushed(ConfirmChannelShippingJob::class);
    }

    public function test_paid_order_goes_straight_to_invoice_and_label(): void
    {
        $this->fake('Unshipped');

        $this->assertSame(Order::STATUS_PAID, $this->webhook()->status);
        Queue::assertPushed(GenerateInvoiceJob::class);
        Queue::assertPushed(ConfirmChannelShippingJob::class);
    }

    public function test_cancelled_on_amazon_is_cancelled_even_if_bling_still_says_open(): void
    {
        $this->fake('Canceled');

        $this->assertSame(Order::STATUS_CANCELLED, $this->webhook()->status);
        Queue::assertNotPushed(ConfirmChannelShippingJob::class);
    }

    /**
     * "Shipped" na Amazon é a nossa confirmação de envio, logo depois da
     * etiqueta: não pode tirar da fila do KoraSync um pacote não embalado.
     */
    public function test_shipped_on_amazon_keeps_the_bling_status_for_the_korasync_queue(): void
    {
        $this->fake(['Unshipped', 'Shipped']);

        $this->webhook();

        $this->assertSame(Order::STATUS_PAID, $this->webhook('order.updated')->status);
    }

    public function test_sp_api_down_holds_the_order_instead_of_shipping_blind(): void
    {
        $this->fake([Http::response(['errors' => [['code' => 'InternalFailure', 'message' => 'erro']]], 500)]);

        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $this->webhook()->status);
        Queue::assertNotPushed(ConfirmChannelShippingJob::class);
    }

    public function test_without_sp_api_the_bling_status_is_kept(): void
    {
        MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_AMAZON)->delete();
        $this->fake('Pending');

        $this->assertSame(Order::STATUS_PAID, $this->webhook()->status);
        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), self::BASE));
    }

    private function pedidoAguardando(string $numero, \DateTimeInterface $criado): void
    {
        Order::create([
            'status' => Order::STATUS_AWAITING_PAYMENT,
            'origin' => Order::ORIGIN_AMAZON,
            'external_order_id' => $numero,
            'shipping_name' => 'Cliente Teste',
            'shipping_phone' => '19999999999',
            'shipping_zip' => '13010000',
            'shipping_street' => 'Rua A',
            'shipping_number' => '10',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'Campinas',
            'shipping_state' => 'SP',
            'subtotal' => 73.92,
            'total' => 73.92,
        ])->forceFill(['created_at' => $criado])->save();
    }

    public function test_hourly_sync_rechecks_held_orders_older_than_its_window(): void
    {
        $this->pedidoAguardando('701-0000000-0000001', now()->subDays(4));
        $this->pedidoAguardando('701-0000000-0000002', now()->subDays(10));
        Http::fake([self::BASE.'/orders/v0/orders*' => Http::response(['payload' => ['Orders' => [['AmazonOrderId' => 'A1']]]])]);

        $importados = [];
        $mock = Mockery::mock(OrderImportService::class);
        $mock->shouldReceive('import')->andReturnUsing(function (string $canal, string $id) use (&$importados) {
            $importados[] = $id;

            return new Order;
        });
        $this->app->instance(OrderImportService::class, $mock);

        $this->artisan('orders:sync-amazon --desde='.now()->subDays(2)->toDateString())->assertSuccessful();

        $this->assertSame(['A1', '701-0000000-0000001'], $importados);
    }
}
