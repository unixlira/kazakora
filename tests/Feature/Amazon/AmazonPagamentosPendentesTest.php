<?php

namespace Tests\Feature\Amazon;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Marketplace\Jobs\ConfirmChannelShippingJob;
use App\Modules\Marketplace\Models\AmazonPagamentoPendente;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\OrderImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Regra do Lira (2026-10-16): pedido da Amazon com pagamento pendente fica
 * bloqueado (sem NF-e, etiqueta, KoraSync), é conferido de 24 em 24 h, e
 * no 8º dia ainda pendente é cancelado no KazaKora.
 */
class AmazonPagamentosPendentesTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://sellingpartnerapi.test';

    private const PEDIDO = '701-1234567-1234567';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.amazon.sp_api_base_url' => self::BASE,
            'services.amazon.marketplace_id' => 'A2Q3Y263D00KWC',
            'services.bling.amazon_loja_id' => null,
            'app.timezone' => 'America/Sao_Paulo',
        ]);

        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_AMAZON, 'status' => MarketplaceAccount::STATUS_CONNECTED,
            'access_token' => 'lwa-token', 'refresh_token' => 'rt', 'token_expires_at' => now()->addMonth(),
        ]);

        Product::factory()->create(['sku' => 'KZ-1', 'stock' => 5]);
        Queue::fake([GenerateInvoiceJob::class, ConfirmChannelShippingJob::class]);
    }

    private string $statusAmazon = 'Pending';

    private function fakeAmazon(string $status): void
    {
        $this->statusAmazon = $status;

        if ($this->amazonFalsa) {
            return;
        }

        $this->amazonFalsa = true;

        Http::fake(function (Request $request) {
            $caminho = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $this->amazonFora => Http::response(['errors' => [['code' => 'InternalFailure']]], 500),
                str_starts_with($caminho, '/tokens/') => Http::response(['restrictedDataToken' => 'rdt']),
                $caminho === '/orders/v0/orders/'.self::PEDIDO.'/orderItems' => Http::response(['payload' => ['OrderItems' => [
                    ['OrderItemId' => '111', 'SellerSKU' => 'KZ-1', 'Title' => 'Capinha', 'QuantityOrdered' => 1, 'ItemPrice' => ['Amount' => '59.80']],
                ]]]),
                $caminho === '/orders/v0/orders/'.self::PEDIDO => Http::response(['payload' => [
                    'AmazonOrderId' => self::PEDIDO, 'OrderStatus' => $this->statusAmazon, 'OrderTotal' => ['Amount' => '75.70'],
                    'PaymentMethod' => 'Other', 'PurchaseDate' => now()->utc()->format('Y-m-d\\TH:i:s\\Z'),
                ]]),
                default => Http::response(['payload' => []]),
            };
        });
    }

    private bool $amazonFalsa = false;

    private bool $amazonFora = false;

    private function pedidoPendente(int $diasAtras = 0): Order
    {
        $this->fakeAmazon('Pending');
        $order = app(OrderImportService::class)->import(MarketplaceAccount::CHANNEL_AMAZON, self::PEDIDO);
        $order->forceFill(['created_at' => now()->subDays($diasAtras)])->save();

        return $order->fresh();
    }

    public function test_pending_order_is_saved_but_gets_no_invoice_label_or_korasync(): void
    {
        $order = $this->pedidoPendente();

        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->status);
        $this->assertCount(1, $order->items);
        Queue::assertNotPushed(GenerateInvoiceJob::class);
        Queue::assertNotPushed(ConfirmChannelShippingJob::class);
    }

    public function test_first_check_saves_the_full_amazon_order_and_keeps_it_blocked(): void
    {
        $order = $this->pedidoPendente();

        $this->artisan('amazon:pagamentos-pendentes')->assertSuccessful();

        $registro = AmazonPagamentoPendente::where('order_id', $order->id)->firstOrFail();
        $this->assertSame('Pending', $registro->status_amazon);
        $this->assertSame(self::PEDIDO, $registro->pedido_amazon['pedido']['AmazonOrderId']);
        $this->assertSame('111', $registro->pedido_amazon['itens']['OrderItems'][0]['OrderItemId']);
        $this->assertSame(1, $registro->conferencias);
        $this->assertNull($registro->resolvido_em);
        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->fresh()->status);
        Queue::assertNotPushed(GenerateInvoiceJob::class);
    }

    public function test_checks_again_only_after_24_hours(): void
    {
        $order = $this->pedidoPendente();
        $this->artisan('amazon:pagamentos-pendentes');

        $this->travel(23)->hours();
        $this->artisan('amazon:pagamentos-pendentes');
        $this->assertSame(1, AmazonPagamentoPendente::where('order_id', $order->id)->value('conferencias'));

        $this->travel(2)->hours();
        $this->artisan('amazon:pagamentos-pendentes');
        $this->assertSame(2, AmazonPagamentoPendente::where('order_id', $order->id)->value('conferencias'));
    }

    public function test_paid_on_amazon_releases_invoice_and_label(): void
    {
        $order = $this->pedidoPendente(3);
        $this->fakeAmazon('Unshipped');

        $this->artisan('amazon:pagamentos-pendentes')->assertSuccessful();

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(AmazonPagamentoPendente::RESULTADO_PAGO, AmazonPagamentoPendente::where('order_id', $order->id)->value('resultado'));
        Queue::assertPushed(GenerateInvoiceJob::class);
        Queue::assertPushed(ConfirmChannelShippingJob::class);
    }

    public function test_paid_on_the_7th_day_still_releases(): void
    {
        $order = $this->pedidoPendente(7);
        $this->fakeAmazon('Unshipped');

        $this->artisan('amazon:pagamentos-pendentes');

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_cancelled_on_amazon_is_cancelled_here_and_restocks(): void
    {
        $order = $this->pedidoPendente(2);
        $estoque = Product::where('sku', 'KZ-1')->value('stock');
        $this->fakeAmazon('Canceled');

        $this->artisan('amazon:pagamentos-pendentes');

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame(AmazonPagamentoPendente::RESULTADO_CANCELADO_AMAZON, AmazonPagamentoPendente::where('order_id', $order->id)->value('resultado'));
        $this->assertSame($estoque + 1, Product::where('sku', 'KZ-1')->value('stock'));
        Queue::assertNotPushed(GenerateInvoiceJob::class);
    }

    public function test_still_pending_on_the_7th_day_is_kept(): void
    {
        $order = $this->pedidoPendente(7);

        $this->artisan('amazon:pagamentos-pendentes');

        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->fresh()->status);
    }

    public function test_still_pending_on_the_8th_day_is_cancelled_only_in_kazakora(): void
    {
        $order = $this->pedidoPendente(7);
        $this->artisan('amazon:pagamentos-pendentes');

        // Conferido há menos de 24 h, mas chegou o 8º dia: confere de novo e cancela.
        $this->travel(1)->days();
        $this->artisan('amazon:pagamentos-pendentes');

        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
        $this->assertSame(AmazonPagamentoPendente::RESULTADO_CANCELADO_PRAZO, AmazonPagamentoPendente::where('order_id', $order->id)->value('resultado'));
        Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET' && ! str_contains($r->url(), '/tokens/'));
        Queue::assertNotPushed(GenerateInvoiceJob::class);
    }

    public function test_paid_after_the_automatic_cancel_reopens_as_paid(): void
    {
        $order = $this->pedidoPendente(8);
        $this->artisan('amazon:pagamentos-pendentes');
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);

        $this->fakeAmazon('Unshipped');
        app(OrderImportService::class)->import(MarketplaceAccount::CHANNEL_AMAZON, self::PEDIDO);

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        Queue::assertPushed(GenerateInvoiceJob::class);
    }

    public function test_sp_api_failure_neither_releases_nor_cancels(): void
    {
        $order = $this->pedidoPendente(9);
        $this->amazonFora = true;

        $this->artisan('amazon:pagamentos-pendentes')->assertSuccessful();

        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->fresh()->status);
    }

    public function test_without_sp_api_does_nothing(): void
    {
        $order = $this->pedidoPendente(9);
        MarketplaceAccount::query()->update(['status' => MarketplaceAccount::STATUS_DISCONNECTED]);

        $this->artisan('amazon:pagamentos-pendentes')->assertSuccessful();

        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->fresh()->status);
        $this->assertSame(0, AmazonPagamentoPendente::count());
    }
}
