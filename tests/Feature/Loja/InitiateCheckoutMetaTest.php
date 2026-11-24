<?php

namespace Tests\Feature\Loja;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Jobs\EnviarEventoMetaJob;
use App\Modules\Checkout\Jobs\EnviarPurchaseMetaJob;
use App\Modules\Checkout\Models\Order;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Services\Meta\ConversoesMeta;
use App\Support\PaymentGateway;
use App\Support\Privacidade\Cookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * API de Conversões da Meta, 2026-11-24: InitiateCheckout do servidor com o
 * mesmo event_id do Pixel, envio de verdade (Http::fake) dos três eventos e
 * log próprio com o events_received.
 */
class InitiateCheckoutMetaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.meta_ads.pixel_id' => '2186107885537817',
            'services.meta_ads.graph_url' => 'https://graph.facebook.com/v23.0',
            'services.meta_capi.enabled' => true,
            'services.meta_capi.access_token' => 'token-de-teste',
            'services.meta_capi.test_event_code' => null,
        ]);
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        ShippingMethod::factory()->create(['price' => 0, 'is_active' => true]);
        // Fila presa: o AddToCart do carrinho não sai de verdade; nada vai à internet.
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function initiateCheckouts(): \Illuminate\Support\Collection
    {
        return Queue::pushed(EnviarEventoMetaJob::class, fn (EnviarEventoMetaJob $job) => $job->evento['event_name'] === 'InitiateCheckout');
    }

    private function noCarrinho(float $preco = 100, int $quantidade = 2): Product
    {
        $product = Product::factory()->create(['price' => $preco, 'stock' => 10, 'is_active' => true]);
        $this->post('/carrinho', ['product_id' => $product->id, 'quantity' => $quantidade]);

        return $product;
    }

    private function eventoDoCheckout(): ?string
    {
        $eventId = null;
        $this->get('/finalizacao')->assertOk()->assertInertia(function (AssertableInertia $page) use (&$eventId) {
            $page->component('Checkout/CheckoutV2', false)->has('metaInitiateCheckout.event_id');
            $eventId = $page->toArray()['props']['metaInitiateCheckout']['event_id'];
        });

        return $eventId;
    }

    public function test_abrir_o_checkout_com_ok_de_cookies_manda_initiate_checkout_com_o_event_id_do_pixel(): void
    {
        $user = User::factory()->create(['email' => 'Ana@Exemplo.com', 'name' => 'Ana Souza', 'phone' => '11988887777']);
        $this->actingAs($user)->withCookie(Cookies::COOKIE_OK, Cookies::VERSAO);
        $product = $this->noCarrinho();

        $eventId = $this->withHeader('Cookie', '_fbp=fb.1.1700000000.111; _fbc=fb.1.1700000000.IwAR')
            ->withHeader('User-Agent', 'Mozilla/5.0 Teste')
            ->eventoDoCheckout();

        $this->assertNotNull(ConversoesMeta::eventIdValido($eventId));
        $this->assertCount(1, $this->initiateCheckouts());
        Queue::assertPushed(EnviarEventoMetaJob::class, function (EnviarEventoMetaJob $job) use ($eventId, $product, $user) {
            $e = $job->evento;
            $preco = round((float) $product->fresh()->final_price, 2);

            return $e['event_name'] === 'InitiateCheckout'
                && $e['event_id'] === $eventId
                && $e['custom_data']['currency'] === 'BRL'
                && $e['custom_data']['content_ids'] === [(string) $product->id]
                && $e['custom_data']['num_items'] === 2
                && abs($e['custom_data']['value'] - $preco * 2) < 0.01
                && $e['user_data']['em'] === [hash('sha256', 'ana@exemplo.com')]
                && $e['user_data']['ph'] === [hash('sha256', '5511988887777')]
                && $e['user_data']['fn'] === hash('sha256', 'ana')
                && $e['user_data']['ln'] === hash('sha256', 'souza')
                && $e['user_data']['external_id'] === hash('sha256', (string) $user->id)
                && $e['user_data']['fbp'] === 'fb.1.1700000000.111'
                && $e['user_data']['fbc'] === 'fb.1.1700000000.IwAR'
                && $e['user_data']['client_user_agent'] === 'Mozilla/5.0 Teste'
                && filled($e['user_data']['client_ip_address']);
        });
    }

    public function test_recarregar_o_checkout_repete_o_event_id_e_nao_manda_de_novo(): void
    {
        $this->withCookie(Cookies::COOKIE_OK, Cookies::VERSAO);
        $this->noCarrinho();

        $primeiro = $this->eventoDoCheckout();
        $segundo = $this->eventoDoCheckout();

        $this->assertSame($primeiro, $segundo);
        $this->assertCount(1, $this->initiateCheckouts());
    }

    public function test_carrinho_mudou_gera_evento_novo(): void
    {
        $this->withCookie(Cookies::COOKIE_OK, Cookies::VERSAO);
        $this->noCarrinho();

        $primeiro = $this->eventoDoCheckout();
        $this->noCarrinho(50, 1);
        $segundo = $this->eventoDoCheckout();

        $this->assertNotSame($primeiro, $segundo);
        $this->assertCount(2, $this->initiateCheckouts());
    }

    public function test_sem_ok_de_cookies_o_pixel_recebe_o_event_id_mas_o_servidor_nao_manda(): void
    {
        $this->noCarrinho();

        $this->assertNotNull($this->eventoDoCheckout());
        $this->assertCount(0, $this->initiateCheckouts());
    }

    public function test_job_manda_a_meta_e_registra_o_events_received_no_log_proprio(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1, 'fbtrace_id' => 'trace-1'])]);
        $log = Mockery::mock(LoggerInterface::class);
        $log->shouldReceive('info')->once()->with('meta_capi.enviado', Mockery::on(fn ($d) => $d['event_name'] === 'InitiateCheckout'
            && $d['event_id'] === 'ic-123456789' && $d['recebidos'] === 1));
        Log::shouldReceive('channel')->with('meta_capi')->andReturn($log);

        (new EnviarEventoMetaJob(['event_name' => 'InitiateCheckout', 'event_id' => 'ic-123456789', 'event_time' => 1, 'action_source' => 'website']))
            ->handle(app(ConversoesMeta::class));

        Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://graph.facebook.com/v23.0/2186107885537817/events?')
            && $r['data'][0]['event_name'] === 'InitiateCheckout'
            && $r['data'][0]['event_id'] === 'ic-123456789'
            && ! isset($r['test_event_code']));
    }

    public function test_codigo_de_teste_do_env_vai_junto_no_envio(): void
    {
        config(['services.meta_capi.test_event_code' => 'TEST98765']);
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);

        (new EnviarEventoMetaJob(['event_name' => 'AddToCart', 'event_id' => 'atc-123456789', 'event_time' => 1, 'action_source' => 'website']))
            ->handle(app(ConversoesMeta::class));

        Http::assertSent(fn (HttpRequest $r) => $r['test_event_code'] === 'TEST98765');
    }

    public function test_purchase_manda_o_valor_pago_com_cupom_e_desconto_pix(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);
        $user = User::factory()->create(['email' => 'cliente@exemplo.com', 'name' => 'Maria Souza']);
        $product = Product::factory()->create(['stock' => 10, 'is_active' => true]);
        $order = Order::create([
            'user_id' => $user->id,
            'origin' => Order::ORIGIN_STORE,
            'status' => Order::STATUS_PAID,
            'shipping_name' => 'Maria Souza',
            'shipping_phone' => '11977776666',
            'shipping_email' => 'cliente@exemplo.com',
            'shipping_zip' => '01310-100',
            'shipping_street' => 'Av. Paulista',
            'shipping_number' => '1000',
            'shipping_neighborhood' => 'Bela Vista',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 200,
            'coupon_code' => 'BLACKFRIDAY',
            'discount_amount' => 20,
            'pix_discount_amount' => 3.6,
            'total' => 176.4,
            'meta_rastreio' => ['ip' => '200.1.2.3', 'ua' => 'Mozilla/5.0 Teste', 'url' => 'https://seguro.kazakora.com/finalizacao', 'fbp' => 'fb.1.1700000000.111', 'fbc' => null, 'consentiu' => Cookies::VERSAO],
        ]);
        $order->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'product_price' => 100, 'quantity' => 2, 'subtotal' => 200]);

        (new EnviarPurchaseMetaJob($order->id))->handle(app(ConversoesMeta::class));

        Http::assertSent(function (HttpRequest $r) use ($order) {
            $e = $r['data'][0];

            return $e['event_name'] === 'Purchase'
                && $e['event_id'] === "purchase-{$order->id}"
                && $e['custom_data']['value'] === 176.4
                && $e['custom_data']['currency'] === 'BRL'
                && $e['user_data']['em'] === [hash('sha256', 'cliente@exemplo.com')]
                && $e['user_data']['ct'] === hash('sha256', 'saopaulo')
                && $e['user_data']['st'] === hash('sha256', 'sp')
                && $e['user_data']['zp'] === hash('sha256', '01310100')
                && $e['user_data']['client_ip_address'] === '200.1.2.3'
                && $e['user_data']['fbp'] === 'fb.1.1700000000.111';
        });
        $this->assertNotNull($order->fresh()->meta_purchase_enviado_em);
    }
}
