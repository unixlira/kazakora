<?php

namespace Tests\Feature\Amazon;

use App\Jobs\ConfirmAmazonShipment;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\OrderImportService;
use App\Services\Bling\BlingOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Comandos agendados da Amazon (orders:sync-amazon, amazon:confirmar-envios)
 * e as funções do BlingOrderService que fazem a ponte com a loja Amazon no
 * Bling (amazonLojaId, channelForLojaId, informarEnvio).
 */
class AmazonComandosEBlingTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://sellingpartnerapi.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.amazon.sp_api_base_url' => self::BASE,
            'services.amazon.marketplace_id' => 'A2Q3Y263D00KWC',
            'services.bling.api_base_url' => 'https://api.bling.test/Api/v3',
            'services.bling.amazon_loja_id' => null,
        ]);
    }

    private function conectarAmazon(string $status = MarketplaceAccount::STATUS_CONNECTED): void
    {
        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_AMAZON, 'status' => $status,
            'access_token' => 'lwa-token', 'refresh_token' => 'rt', 'token_expires_at' => now()->addHour(),
        ]);
    }

    private function conectarBling(array $metadata = []): void
    {
        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_BLING, 'status' => MarketplaceAccount::STATUS_CONNECTED,
            'access_token' => 't', 'refresh_token' => 'r', 'token_expires_at' => now()->addHour(), 'metadata' => $metadata ?: null,
        ]);
    }

    private function importador(array &$importados, array $falhar = []): void
    {
        $mock = Mockery::mock(OrderImportService::class);
        $mock->shouldReceive('import')->andReturnUsing(function (string $canal, string $id) use (&$importados, $falhar) {
            if (in_array($id, $falhar, true)) {
                throw new \RuntimeException('falhou');
            }
            $importados[] = "{$canal}:{$id}";

            return new Order;
        });
        $this->app->instance(OrderImportService::class, $mock);
    }

    public function test_sync_without_any_connection_fails(): void
    {
        $this->artisan('orders:sync-amazon')->expectsOutputToContain('Amazon não está conectada')->assertFailed();
    }

    public function test_sync_by_sp_api_follows_next_token_and_imports_every_order(): void
    {
        $this->conectarAmazon();
        Http::fake([self::BASE.'/orders/v0/orders*' => Http::sequence()
            ->push(['payload' => ['Orders' => [['AmazonOrderId' => 'A1'], ['AmazonOrderId' => 'A2']], 'NextToken' => 'pag2']])
            ->push(['payload' => ['Orders' => [['AmazonOrderId' => 'A3']]]]),
        ]);
        $importados = [];
        $this->importador($importados, falhar: ['A2']);

        $this->artisan('orders:sync-amazon --desde=2026-10-01 --ate=2026-10-10')
            ->expectsOutputToContain('3 pedido(s) encontrado(s)')
            ->expectsOutputToContain('2 sincronizado(s), 1 com erro')
            ->assertSuccessful();

        $this->assertSame(['amazon:A1', 'amazon:A3'], $importados);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'NextToken=pag2'));
    }

    public function test_sync_never_asks_amazon_for_orders_newer_than_two_minutes(): void
    {
        // Resposta REAL de 2026-10-16: CreatedBefore = agora dá HTTP 400
        // InvalidInput ("The most updated data is 2 minutes after..."). A
        // SP-API exige CreatedBefore pelo menos 2 minutos no passado.
        Carbon::setTestNow('2026-10-16 15:00:00');
        $this->conectarAmazon();
        Http::fake([self::BASE.'/*' => Http::response(['payload' => ['Orders' => []]])]);
        $importados = [];
        $this->importador($importados);

        $this->artisan('orders:sync-amazon --desde=2026-10-14')->assertSuccessful();

        Http::assertSent(function (Request $r) {
            parse_str(parse_url($r->url(), PHP_URL_QUERY), $q);

            return Carbon::parse($q['CreatedBefore'])->lte(now()->subMinutes(2));
        });
    }

    public function test_sync_with_sp_api_error_stops_with_the_amazon_message(): void
    {
        $this->conectarAmazon();
        Http::fake([self::BASE.'/*' => Http::response(['errors' => [['code' => 'QuotaExceeded', 'message' => 'You exceeded your quota']]], 429)]);
        $importados = [];
        $this->importador($importados);

        $this->expectException(\App\Services\Amazon\Exceptions\AmazonException::class);

        $this->artisan('orders:sync-amazon --desde=2026-10-01 --ate=2026-10-02');
    }

    public function test_sync_with_sp_api_in_error_uses_the_bling_store(): void
    {
        // Estado REAL da produção desde 12/08: conta SP-API "error" + loja no Bling.
        $this->conectarAmazon(MarketplaceAccount::STATUS_ERROR);
        config(['services.bling.amazon_loja_id' => 206308488]);
        $bling = Mockery::mock(BlingOrderService::class)->makePartial();
        $bling->shouldReceive('amazonLojaId')->andReturn(206308488);
        $bling->shouldReceive('listRecentOrderNumbers')->once()->withArgs(fn ($de, $ate, $loja) => $loja === 206308488)->andReturn(['701-1', '701-2']);
        $this->app->instance(BlingOrderService::class, $bling);
        $importados = [];
        $this->importador($importados);
        Http::fake();

        $this->artisan('orders:sync-amazon --desde=2026-10-01')->expectsOutputToContain('via Bling, loja 206308488')->assertSuccessful();

        $this->assertSame(['amazon:701-1', 'amazon:701-2'], $importados);
        Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), self::BASE));
    }

    public function test_confirmar_envios_does_nothing_without_sp_api(): void
    {
        Queue::fake();
        $this->conectarAmazon(MarketplaceAccount::STATUS_ERROR);

        $this->artisan('amazon:confirmar-envios')->expectsOutputToContain('não conectada')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_confirmar_envios_only_queues_amazon_postings_not_yet_confirmed_inside_the_window(): void
    {
        Queue::fake();
        $this->conectarAmazon();

        $criar = function (string $origem, ?string $confirmado, string $status, int $diasAtras, string $statusPedido = Order::STATUS_PAID) {
            $order = Order::create([
                'status' => $statusPedido, 'origin' => $origem, 'external_order_id' => uniqid(),
                'shipping_name' => 'M', 'shipping_phone' => '1', 'shipping_zip' => '13010000', 'shipping_street' => 'R',
                'shipping_number' => '1', 'shipping_neighborhood' => 'C', 'shipping_city' => 'X', 'shipping_state' => 'SP',
                'subtotal' => 1, 'total' => 1,
            ]);
            $order->forceFill(['created_at' => now()->subDays($diasAtras)])->save();
            CorreiosPrePostagem::create([
                'order_id' => $order->id, 'origin' => $origem, 'customer_name' => 'M', 'zip' => '13010000', 'street' => 'R', 'number' => '1',
                'neighborhood' => 'C', 'city' => 'X', 'state' => 'SP', 'service_code' => '03298', 'service_label' => 'PAC',
                'weight_grams' => 100, 'dimension_format' => '1', 'content_items' => [], 'status' => $status,
                'correios_id' => uniqid(), 'codigo_objeto' => 'AA'.random_int(100000000, 999999999).'BR', 'amazon_confirmado_em' => $confirmado,
            ]);

            return $order->id;
        };

        $certo = $criar(Order::ORIGIN_AMAZON, null, CorreiosPrePostagem::STATUS_GERADA, 3);
        $criar(Order::ORIGIN_AMAZON, now()->toDateTimeString(), CorreiosPrePostagem::STATUS_GERADA, 3);
        $criar(Order::ORIGIN_AMAZON, null, CorreiosPrePostagem::STATUS_GERADA, 20);
        $criar(Order::ORIGIN_AMAZON, null, CorreiosPrePostagem::STATUS_GERADA, 3, Order::STATUS_CANCELLED);
        $criar('shopee', null, CorreiosPrePostagem::STATUS_GERADA, 3);

        $this->artisan('amazon:confirmar-envios')->expectsOutputToContain('1 envio(s)')->assertSuccessful();

        Queue::assertPushed(ConfirmAmazonShipment::class, 1);
        Queue::assertPushed(ConfirmAmazonShipment::class, fn ($job) => $job->orderId === $certo);
    }

    public function test_bling_store_id_comes_from_the_bling_account_first_and_zero_turns_it_off(): void
    {
        $bling = app(BlingOrderService::class);
        config(['services.bling.amazon_loja_id' => 206308488]);
        $this->assertSame(206308488, $bling->amazonLojaId());

        $this->conectarBling(['amazon_loja_id' => 777]);
        $this->assertSame(777, $bling->amazonLojaId());
        $this->assertSame('amazon', $bling->channelForLojaId('777'));
        $this->assertNull($bling->channelForLojaId(206308488));
        $this->assertNull($bling->channelForLojaId(0));

        MarketplaceAccount::query()->delete();
        config(['services.bling.amazon_loja_id' => 0]);
        $this->assertNull($bling->amazonLojaId());
    }

    public function test_informar_envio_with_logistics_service_creates_a_tracking_object(): void
    {
        $this->conectarBling();
        config(['services.bling.amazon_envio' => ['informar' => true, 'logistica_servico_id' => 55, 'situacao_id' => 9]]);
        Http::fake(['api.bling.test/*' => Http::response(['data' => ['id' => 1]])]);

        app(BlingOrderService::class)->informarEnvio(123, 'AA123BR', 'Correios PAC', 456);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/logisticas/objetos')
            && $r['pedidoVenda'] === ['id' => 123] && $r['notaFiscal'] === ['id' => 456] && $r['servico'] === ['id' => 55]
            && $r['rastreamento']['codigo'] === 'AA123BR');
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/pedidos/vendas/123/situacoes/9'));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PUT');
    }

    public function test_informar_envio_without_situacao_keeps_the_bling_status(): void
    {
        $this->conectarBling();
        config(['services.bling.amazon_envio' => ['informar' => true, 'logistica_servico_id' => null, 'situacao_id' => null]]);
        Http::fake(['api.bling.test/*' => fn (Request $r) => $r->method() === 'GET'
            ? Http::response(['data' => ['id' => 123, 'itens' => [['codigo' => 'X']], 'transporte' => ['volumes' => []]]])
            : Http::response(['data' => ['id' => 123]])]);

        app(BlingOrderService::class)->informarEnvio(123, 'AA123BR', 'Correios PAC');

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['transporte']['volumes'] === [['servico' => 'Correios PAC', 'codigoRastreamento' => 'AA123BR']]
            && $r['itens'] === [['codigo' => 'X']]);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    }

    public function test_informar_envio_for_missing_bling_order_raises_404(): void
    {
        $this->conectarBling();
        config(['services.bling.amazon_envio' => ['informar' => true, 'logistica_servico_id' => null, 'situacao_id' => 9]]);
        Http::fake(['api.bling.test/*' => Http::response(['data' => null])]);

        $this->expectException(\App\Services\Bling\Exceptions\BlingException::class);
        $this->expectExceptionCode(404);

        app(BlingOrderService::class)->informarEnvio(999, 'AA1BR', 'PAC');
    }
}
