<?php

namespace Tests\Feature\Loja;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Jobs\EnviarEventoMetaJob;
use App\Modules\Checkout\Jobs\EnviarPurchaseMetaJob;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Checkout\Support\OrderPaymentFinalizer;
use App\Services\Meta\ConversoesMeta;
use App\Support\Privacidade\Cookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** API de Conversões da Meta: AddToCart e Purchase com o mesmo event_id do Pixel (2026-10-18). */
class ConversoesMetaTest extends TestCase
{
    use RefreshDatabase;

    private const EVENT_ID = '3f0c2a52-7d1e-4b8e-9a51-1c2d3e4f5a6b';

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
    }

    private function pedidoDaLoja(array $extra = [], string $email = 'Cliente.Teste@Example.com'): Order
    {
        $user = User::factory()->create(['email' => $email, 'name' => 'José da Silva', 'phone' => '(11) 98888-7777']);
        $product = Product::factory()->create(['stock' => 10, 'is_active' => true]);

        $order = Order::create([
            'user_id' => $user->id,
            'origin' => Order::ORIGIN_STORE,
            'external_order_id' => '261018ABC',
            'status' => Order::STATUS_AWAITING_PAYMENT,
            'shipping_name' => 'José da Silva',
            'shipping_phone' => '11988887777',
            'shipping_zip' => '01310-100',
            'shipping_street' => 'Av. Paulista',
            'shipping_number' => '1000',
            'shipping_neighborhood' => 'Bela Vista',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 149.9,
            'total' => 149.9,
            'meta_rastreio' => ['ip' => '200.100.50.25', 'ua' => 'Mozilla/5.0 Teste', 'url' => 'https://kazakora.com/finalizacao', 'fbp' => 'fb.1.1700000000.111', 'fbc' => 'fb.1.1700000000.IwAR', 'consentiu' => Cookies::VERSAO],
            ...$extra,
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_price' => 74.95,
            'quantity' => 2,
            'subtotal' => 149.9,
        ]);

        return $order;
    }

    // ---------- Serviço ----------

    public function test_envia_o_evento_para_o_pixel_com_token_e_codigo_de_teste(): void
    {
        config(['services.meta_capi.test_event_code' => 'TEST12345']);
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1, 'fbtrace_id' => 'abc'])]);

        $ok = app(ConversoesMeta::class)->enviar(['event_name' => 'AddToCart', 'event_id' => self::EVENT_ID, 'event_time' => 1, 'action_source' => 'website']);

        $this->assertTrue($ok);
        Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://graph.facebook.com/v23.0/2186107885537817/events?')
            && str_contains($r->url(), 'access_token=token-de-teste')
            && $r['test_event_code'] === 'TEST12345'
            && $r['data'][0]['event_id'] === self::EVENT_ID
            && $r['data'][0]['event_name'] === 'AddToCart');
    }

    public function test_sem_token_ou_desligado_nao_manda_nada(): void
    {
        Http::fake();

        config(['services.meta_capi.access_token' => null]);
        $this->assertFalse(app(ConversoesMeta::class)->enviar(['event_name' => 'AddToCart', 'event_id' => self::EVENT_ID]));

        config(['services.meta_capi.access_token' => 'token', 'services.meta_capi.enabled' => false]);
        $this->assertFalse(app(ConversoesMeta::class)->enviar(['event_name' => 'AddToCart', 'event_id' => self::EVENT_ID]));

        Http::assertNothingSent();
    }

    public function test_erro_da_meta_lanca_para_a_fila_tentar_de_novo(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid OAuth access token']], 400)]);

        $this->expectException(\Illuminate\Http\Client\RequestException::class);
        app(ConversoesMeta::class)->enviar(['event_name' => 'Purchase', 'event_id' => 'purchase-1']);
    }

    public function test_dados_do_cliente_normalizados_e_com_hash(): void
    {
        $user = User::factory()->make(['id' => 42, 'email' => '  Fulano@Exemplo.COM ', 'name' => 'Fulano', 'phone' => null]);

        $dados = app(ConversoesMeta::class)->dadosDoCliente($user, [
            'telefone' => '(11) 3333-4444', 'nome' => 'Ana Maria Gonçalves', 'cidade' => 'São José dos Campos', 'uf' => 'SP', 'cep' => '12.245-000',
        ]);

        $this->assertSame([hash('sha256', 'fulano@exemplo.com')], $dados['em']);
        $this->assertSame([hash('sha256', '551133334444')], $dados['ph']);
        $this->assertSame(hash('sha256', 'ana'), $dados['fn']);
        $this->assertSame(hash('sha256', 'gonçalves'), $dados['ln']);
        $this->assertSame(hash('sha256', 'saojosedoscampos'), $dados['ct']);
        $this->assertSame(hash('sha256', 'sp'), $dados['st']);
        $this->assertSame(hash('sha256', '12245000'), $dados['zp']);
        $this->assertSame(hash('sha256', 'br'), $dados['country']);
        $this->assertSame(hash('sha256', '42'), $dados['external_id']);
    }

    // ---------- AddToCart ----------

    public function test_add_to_cart_com_ok_de_cookies_vai_para_a_fila_com_o_event_id_do_front(): void
    {
        Queue::fake();
        $product = Product::factory()->create(['stock' => 5, 'is_active' => true]);

        $this->withCookie(Cookies::COOKIE_OK, Cookies::VERSAO)
            ->withHeader('Cookie', '_fbp=fb.1.1700000000.111; _fbc=fb.1.1700000000.IwAR')
            ->withHeader('User-Agent', 'Mozilla/5.0 Teste')
            ->from('/produtos/'.$product->slug)
            ->post('/carrinho', ['product_id' => $product->id, 'quantity' => 2, 'meta_event_id' => self::EVENT_ID])
            ->assertRedirect();

        Queue::assertPushed(EnviarEventoMetaJob::class, function (EnviarEventoMetaJob $job) use ($product) {
            $e = $job->evento;

            return $e['event_name'] === 'AddToCart'
                && $e['event_id'] === self::EVENT_ID
                && $e['action_source'] === 'website'
                && str_ends_with($e['event_source_url'], '/produtos/'.$product->slug)
                && $e['custom_data']['currency'] === 'BRL'
                && $e['custom_data']['content_type'] === 'product'
                && $e['custom_data']['content_ids'] === [(string) $product->id]
                && abs($e['custom_data']['value'] - round($product->fresh()->final_price * 2, 2)) < 0.01
                && $e['user_data']['fbp'] === 'fb.1.1700000000.111'
                && $e['user_data']['fbc'] === 'fb.1.1700000000.IwAR'
                && $e['user_data']['client_user_agent'] === 'Mozilla/5.0 Teste'
                && filled($e['user_data']['client_ip_address']);
        });
    }

    public function test_add_to_cart_sem_ok_de_cookies_nao_manda_nada(): void
    {
        Queue::fake();
        $product = Product::factory()->create(['stock' => 5, 'is_active' => true]);

        $this->post('/carrinho', ['product_id' => $product->id, 'quantity' => 1, 'meta_event_id' => self::EVENT_ID]);

        Queue::assertNotPushed(EnviarEventoMetaJob::class);
    }

    public function test_comprar_na_pagina_do_produto_tambem_e_add_to_cart(): void
    {
        Queue::fake();
        $product = Product::factory()->create(['stock' => 5, 'is_active' => true]);

        $this->withCookie(Cookies::COOKIE_OK, Cookies::VERSAO)
            ->post('/comprar-agora', ['product_id' => $product->id, 'quantity' => 1, 'meta_event_id' => self::EVENT_ID]);

        Queue::assertPushed(EnviarEventoMetaJob::class, fn (EnviarEventoMetaJob $job) => $job->evento['event_id'] === self::EVENT_ID
            && $job->evento['custom_data']['content_ids'] === [(string) $product->id]);
    }

    public function test_event_id_invalido_vira_um_novo(): void
    {
        Queue::fake();
        $product = Product::factory()->create(['stock' => 5, 'is_active' => true]);

        $this->withCookie(Cookies::COOKIE_OK, Cookies::VERSAO)
            ->post('/carrinho', ['product_id' => $product->id, 'quantity' => 1, 'meta_event_id' => '<script>']);

        Queue::assertPushed(EnviarEventoMetaJob::class, fn (EnviarEventoMetaJob $job) => $job->evento['event_id'] !== '<script>'
            && ConversoesMeta::eventIdValido($job->evento['event_id']) !== null);
    }

    // ---------- Purchase ----------

    public function test_pagamento_aprovado_do_site_dispara_o_purchase(): void
    {
        Queue::fake();
        $order = $this->pedidoDaLoja();
        $order->payments()->create(['mercadopago_payment_id' => '123', 'provider' => Payment::PROVIDER_MERCADOPAGO, 'method_type' => Payment::METHOD_PIX, 'amount' => 149.9, 'status' => Payment::STATUS_CAPTURED]);

        $this->assertTrue(app(OrderPaymentFinalizer::class)->finalize($order->fresh()));

        Queue::assertPushed(EnviarPurchaseMetaJob::class, fn (EnviarPurchaseMetaJob $job) => $job->orderId === $order->id);
    }

    public function test_pedido_de_marketplace_ou_de_outro_site_nao_dispara_o_purchase(): void
    {
        Queue::fake();
        $ml = $this->pedidoDaLoja(['origin' => 'mercado_livre', 'external_order_id' => 'ML-1']);
        $ml->payments()->create(['provider' => Payment::PROVIDER_MERCADOPAGO, 'method_type' => Payment::METHOD_PIX, 'amount' => 149.9, 'status' => Payment::STATUS_CAPTURED]);
        $ferrakora = $this->pedidoDaLoja(['site_origem' => Order::SITE_FERRAKORA, 'external_order_id' => 'FK-1'], 'outro@example.com');
        $ferrakora->payments()->create(['provider' => Payment::PROVIDER_MERCADOPAGO, 'method_type' => Payment::METHOD_PIX, 'amount' => 149.9, 'status' => Payment::STATUS_CAPTURED]);

        app(OrderPaymentFinalizer::class)->finalize($ml->fresh());
        app(OrderPaymentFinalizer::class)->finalize($ferrakora->fresh());

        Queue::assertNotPushed(EnviarPurchaseMetaJob::class);

        // E o job, se rodar mesmo assim, não manda.
        Http::fake();
        $ferrakora->update(['status' => Order::STATUS_PAID]);
        (new EnviarPurchaseMetaJob($ferrakora->id))->handle(app(ConversoesMeta::class));
        Http::assertNothingSent();
    }

    public function test_purchase_sai_uma_vez_so_com_event_id_fixo_e_dados_do_pedido(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1])]);
        $order = $this->pedidoDaLoja(['status' => Order::STATUS_PAID]);

        (new EnviarPurchaseMetaJob($order->id))->handle(app(ConversoesMeta::class));
        (new EnviarPurchaseMetaJob($order->id))->handle(app(ConversoesMeta::class));

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $r) use ($order) {
            $e = $r['data'][0];

            return $e['event_name'] === 'Purchase'
                && $e['event_id'] === "purchase-{$order->id}"
                && $e['event_source_url'] === 'https://kazakora.com/finalizacao'
                && $e['custom_data']['value'] == 149.9
                && $e['custom_data']['currency'] === 'BRL'
                && $e['custom_data']['order_id'] === '261018ABC'
                && $e['custom_data']['content_ids'] === [(string) $order->items->first()->product_id]
                && $e['user_data']['em'] === [hash('sha256', 'cliente.teste@example.com')]
                && $e['user_data']['ph'] === [hash('sha256', '5511988887777')]
                && $e['user_data']['ct'] === hash('sha256', 'saopaulo')
                && $e['user_data']['zp'] === hash('sha256', '01310100')
                && $e['user_data']['fbp'] === 'fb.1.1700000000.111'
                && $e['user_data']['client_ip_address'] === '200.100.50.25';
        });

        $order->refresh();
        $this->assertNotNull($order->meta_purchase_enviado_em);
        $this->assertNull($order->meta_rastreio, 'rastreio apagado depois do envio');
    }

    public function test_falha_da_meta_libera_a_trava_para_tentar_de_novo(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'fora']], 500)]);
        $order = $this->pedidoDaLoja(['status' => Order::STATUS_PAID]);

        try {
            (new EnviarPurchaseMetaJob($order->id))->handle(app(ConversoesMeta::class));
            $this->fail('devia lançar');
        } catch (\Illuminate\Http\Client\RequestException) {
        }

        $order->refresh();
        $this->assertNull($order->meta_purchase_enviado_em);
        $this->assertNotNull($order->meta_rastreio);
    }

    public function test_sem_ok_de_cookies_no_checkout_o_purchase_do_servidor_nao_sai(): void
    {
        Http::fake();
        $order = $this->pedidoDaLoja(['status' => Order::STATUS_PAID, 'meta_rastreio' => null]);

        (new EnviarPurchaseMetaJob($order->id))->handle(app(ConversoesMeta::class));

        Http::assertNothingSent();
        $this->assertNull($order->fresh()->meta_purchase_enviado_em);
    }

    public function test_desligado_nao_marca_o_pedido(): void
    {
        Http::fake();
        config(['services.meta_capi.enabled' => false]);
        $order = $this->pedidoDaLoja(['status' => Order::STATUS_PAID]);

        (new EnviarPurchaseMetaJob($order->id))->handle(app(ConversoesMeta::class));

        Http::assertNothingSent();
        $this->assertNull($order->fresh()->meta_purchase_enviado_em);
    }

    public function test_status_do_pagamento_aprovado_entrega_ao_front_o_mesmo_event_id(): void
    {
        $order = $this->pedidoDaLoja(['status' => Order::STATUS_PAID]);

        $this->actingAs($order->user)->getJson("/finalizacao/{$order->id}/status")
            ->assertOk()
            ->assertJsonPath('status', Order::STATUS_PAID)
            ->assertJsonPath('meta_purchase.event_id', "purchase-{$order->id}")
            ->assertJsonPath('meta_purchase.dados.currency', 'BRL')
            ->assertJsonPath('meta_purchase.dados.order_id', '261018ABC');
    }

    public function test_status_ainda_nao_pago_nao_entrega_purchase(): void
    {
        $order = $this->pedidoDaLoja();

        $this->actingAs($order->user)->getJson("/finalizacao/{$order->id}/status")
            ->assertOk()
            ->assertJsonPath('meta_purchase', null);
    }

    public function test_confirmacao_paga_leva_o_purchase_e_nao_mostra_o_rastreio(): void
    {
        $order = $this->pedidoDaLoja(['status' => Order::STATUS_PAID]);

        $this->actingAs($order->user)->get("/finalizacao/{$order->id}/confirmacao")
            ->assertOk()
            ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page
                ->component('Checkout/Confirmation', false)
                ->where('metaPurchase.event_id', "purchase-{$order->id}")
                ->missing('order.meta_rastreio'));
    }

    public function test_checkout_so_guarda_o_rastreio_com_ok_de_cookies(): void
    {
        $conversoes = app(ConversoesMeta::class);

        $semOk = \Illuminate\Http\Request::create('/finalizacao/pagamento', 'POST');
        $this->assertNull($conversoes->rastreioDoPedido($semOk));

        $comOk = \Illuminate\Http\Request::create('/finalizacao/pagamento', 'POST', [], [Cookies::COOKIE_OK => Cookies::VERSAO], [], [
            'HTTP_COOKIE' => '_fbp=fb.1.1700000000.111', 'HTTP_USER_AGENT' => 'Mozilla/5.0 Teste', 'REMOTE_ADDR' => '200.1.2.3',
        ]);
        $rastreio = $conversoes->rastreioDoPedido($comOk);
        $this->assertSame('fb.1.1700000000.111', $rastreio['fbp']);
        $this->assertSame('200.1.2.3', $rastreio['ip']);
        $this->assertSame(Cookies::VERSAO, $rastreio['consentiu']);
    }
}
