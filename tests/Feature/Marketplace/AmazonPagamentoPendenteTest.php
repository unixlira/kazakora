<?php

namespace Tests\Feature\Marketplace;

use App\Jobs\ProcessBlingOrderWebhook;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Fiscal\Services\InvoiceService;
use App\Modules\Marketplace\Jobs\ConfirmChannelShippingJob;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\ChannelWebhookLog;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Services\Bling\BlingInvoiceImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Regra do Lira (2026-10-16): pedido da Amazon só é expedido (NF-e,
 * etiqueta, fila do KoraSync) com o pagamento confirmado NA AMAZON. A
 * situação do Bling ("Em aberto") não basta.
 */
class AmazonPagamentoPendenteTest extends TestCase
{
    use RefreshDatabase;

    private const LOJA_AMAZON = 206308488;

    private const NUMERO_AMAZON = '701-7654321-7654321';

    private const BLING_ID = 99887766554;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.bling.api_base_url' => 'https://api.bling.test/Api/v3',
            'services.bling.amazon_loja_id' => self::LOJA_AMAZON,
            'services.bling.invoice_issuer_channels' => [Order::ORIGIN_TIKTOK_SHOP],
        ]);

        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_BLING,
            'status' => MarketplaceAccount::STATUS_CONNECTED,
            'access_token' => 'token-de-teste',
            'refresh_token' => 'refresh-de-teste',
            'token_expires_at' => now()->addHour(),
        ]);

        Product::factory()->create(['sku' => 'KZ-GARRAFA-001', 'stock' => 5]);
    }

    public function test_order_pending_on_amazon_is_imported_without_invoice_nor_label(): void
    {
        Queue::fake([GenerateInvoiceJob::class, ConfirmChannelShippingJob::class]);
        $this->amazonComPedidoNoStatus('Pending');
        $this->fakeBlingOrder();

        $this->recebeWebhook();

        $order = $this->pedido();
        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->status, 'Em aberto no Bling, mas Pending na Amazon: não está pago.');
        Queue::assertNotPushed(GenerateInvoiceJob::class);
        Queue::assertNotPushed(ConfirmChannelShippingJob::class);
    }

    public function test_once_amazon_confirms_the_payment_invoice_and_label_go_out(): void
    {
        Queue::fake([GenerateInvoiceJob::class, ConfirmChannelShippingJob::class]);
        $this->amazonComPedidoNoStatus('Pending');
        $this->fakeBlingOrder();
        $this->recebeWebhook();

        Http::swap(new \Illuminate\Http\Client\Factory);
        $this->amazonComPedidoNoStatus('Unshipped');
        $this->fakeBlingOrder();
        $this->recebeWebhook('order.updated');

        $this->assertSame(Order::STATUS_PAID, $this->pedido()->status);
        Queue::assertPushed(GenerateInvoiceJob::class);
        Queue::assertPushed(ConfirmChannelShippingJob::class);
    }

    public function test_amazon_unreachable_never_releases_the_order(): void
    {
        Queue::fake([GenerateInvoiceJob::class, ConfirmChannelShippingJob::class]);
        config(['services.amazon.sp_api_base_url' => 'https://sellingpartnerapi.test']);
        $this->amazonComPedidoNoStatus();
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['sellingpartnerapi.test/*' => Http::response('<html>Service Unavailable</html>', 503)]);
        $this->fakeBlingOrder();

        $this->recebeWebhook();

        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $this->pedido()->status);
        Queue::assertNotPushed(GenerateInvoiceJob::class);
    }

    public function test_invoice_is_held_and_order_leaves_the_korasync_queue_when_payment_is_pending(): void
    {
        $this->amazonComPedidoNoStatus('Pending');
        $order = $this->pedidoPago();

        $invoices = Mockery::mock(InvoiceService::class);
        $invoices->shouldNotReceive('issue');
        $this->app->instance(InvoiceService::class, $invoices);
        $bling = Mockery::mock(BlingInvoiceImporter::class);
        $bling->shouldNotReceive('syncForOrder');
        $this->app->instance(BlingInvoiceImporter::class, $bling);

        dispatch_sync(new GenerateInvoiceJob($order->id));

        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->fresh()->status);
        $this->assertNull($order->fresh()->invoice);
    }

    public function test_label_is_held_when_payment_is_pending(): void
    {
        $this->amazonComPedidoNoStatus('PendingAvailability');
        $order = $this->pedidoPago();

        dispatch_sync(new ConfirmChannelShippingJob($order->id));

        $this->assertSame(0, CorreiosPrePostagem::query()->count(), 'Nenhum objeto nos Correios para pedido não pago.');
        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->fresh()->status);
        $this->assertStringContainsString('pagamento', ChannelShipment::query()->where('order_id', $order->id)->value('error_message'));
    }

    public function test_order_cancelled_on_amazon_is_cancelled_here_instead_of_invoiced(): void
    {
        $this->amazonComPedidoNoStatus('Canceled');
        $order = $this->pedidoPago();

        $invoices = Mockery::mock(InvoiceService::class);
        $invoices->shouldNotReceive('issue');
        $this->app->instance(InvoiceService::class, $invoices);

        dispatch_sync(new GenerateInvoiceJob($order->id));

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
    }

    private function pedido(): Order
    {
        return Order::query()->where('origin', Order::ORIGIN_AMAZON)->where('external_order_id', self::NUMERO_AMAZON)->firstOrFail();
    }

    private function pedidoPago(): Order
    {
        return Order::create([
            'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_AMAZON, 'external_order_id' => self::NUMERO_AMAZON,
            'shipping_name' => 'Maria', 'shipping_phone' => '19999999999', 'shipping_zip' => '13010000', 'shipping_street' => 'Rua das Flores',
            'shipping_number' => '10', 'shipping_neighborhood' => 'Centro', 'shipping_city' => 'Campinas', 'shipping_state' => 'SP',
            'subtotal' => 149.90, 'total' => 149.90,
        ]);
    }

    private function recebeWebhook(string $event = 'order.created'): void
    {
        $payload = [
            'eventId' => 'evento-'.$event,
            'event' => $event,
            'data' => ['id' => self::BLING_ID, 'numeroLoja' => self::NUMERO_AMAZON, 'loja' => ['id' => self::LOJA_AMAZON]],
        ];

        $log = ChannelWebhookLog::create([
            'channel' => MarketplaceAccount::CHANNEL_AMAZON,
            'event_type' => $event,
            'payload' => $payload,
            'headers' => [],
            'signature_valid' => true,
            'status' => ChannelWebhookLog::STATUS_RECEIVED,
        ]);

        dispatch_sync(new ProcessBlingOrderWebhook($payload, $log->id));
    }

    private function fakeBlingOrder(): void
    {
        Http::fake([
            '*/pedidos/vendas/'.self::BLING_ID => Http::response(['data' => [
                'id' => self::BLING_ID,
                'numeroLoja' => self::NUMERO_AMAZON,
                'data' => '2026-10-16',
                'total' => 149.90,
                'loja' => ['id' => self::LOJA_AMAZON],
                'situacao' => ['id' => 6],
                'contato' => ['id' => 1111, 'nome' => 'Maria Compradora', 'numeroDocumento' => '123.456.789-09'],
                'desconto' => ['valor' => 0],
                'taxas' => ['taxaComissao' => 18.5, 'custoFrete' => 0, 'valorBase' => 149.90],
                'itens' => [['codigo' => 'KZ-GARRAFA-001', 'descricao' => 'Garrafa Térmica 500ml', 'quantidade' => 1, 'valor' => 129.90]],
                'transporte' => [
                    'frete' => 20.00,
                    'etiqueta' => [
                        'endereco' => 'Rua das Flores', 'numero' => '10', 'bairro' => 'Centro',
                        'municipio' => 'Campinas', 'uf' => 'SP', 'cep' => '13010-000',
                    ],
                    'volumes' => [['codigoRastreamento' => '']],
                ],
            ]]),
            '*/pedidos/vendas?*' => Http::response(['data' => []]),
            '*/situacoes/6' => Http::response(['data' => ['id' => 6, 'nome' => 'Em aberto']]),
            '*/contatos/1111' => Http::response(['data' => ['celular' => '19999999999', 'email' => 'maria@example.com']]),
        ]);
    }
}
