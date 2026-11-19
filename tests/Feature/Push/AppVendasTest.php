<?php

namespace Tests\Feature\Push;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Checkout\Support\OrderPaymentFinalizer;
use App\Modules\Notifications\Push\AvisosPush;
use App\Modules\Notifications\Push\Jobs\AvisarVendaPorPushJob;
use App\Modules\Notifications\Push\Models\PushInscricao;
use App\Modules\Notifications\Push\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Apps de vendas KazaKora e FerraKora: inscrição e aviso de venda por Web Push (2026-11-19). */
class AppVendasTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT_KZ = 'https://fcm.googleapis.com/fcm/send/kazakora-1';

    private const ENDPOINT_FK = 'https://web.push.apple.com/ferrakora-1';

    protected function setUp(): void
    {
        parent::setUp();

        $chaves = WebPush::gerarChaves();
        config([
            'services.webpush.public_key' => $chaves['publica'],
            'services.webpush.private_key' => $chaves['privada'],
            'services.webpush.subject' => 'mailto:korashopecom@gmail.com',
            'services.webpush.painel_url' => 'https://painel.kazakora.com',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    /** @return array{endpoint: string, keys: array{p256dh: string, auth: string}} */
    private function inscricao(string $endpoint): array
    {
        return ['endpoint' => $endpoint, 'keys' => ['p256dh' => WebPush::gerarChaves()['publica'], 'auth' => WebPush::base64urlEncode(random_bytes(16))]];
    }

    private function inscrever(User $user, string $app, string $endpoint): void
    {
        $this->actingAs($user)->postJson("/admin/vendas-app/{$app}/inscrever", $this->inscricao($endpoint))->assertOk();
    }

    private function pedido(array $extra = []): Order
    {
        $cliente = User::factory()->create(['name' => 'Maria Cliente Sigilosa']);
        $produto = Product::factory()->create(['stock' => 10, 'is_active' => true]);
        $outro = Product::factory()->create(['stock' => 10, 'is_active' => true]);

        $order = Order::create([
            'user_id' => $cliente->id,
            'origin' => Order::ORIGIN_STORE,
            'external_order_id' => 'PED-'.uniqid(),
            'status' => Order::STATUS_AWAITING_PAYMENT,
            'shipping_name' => 'Maria Cliente Sigilosa',
            'shipping_phone' => '11988887777',
            'shipping_zip' => '01310-100',
            'shipping_street' => 'Rua Secreta',
            'shipping_number' => '1000',
            'shipping_neighborhood' => 'Bela Vista',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 172.46,
            'total' => 172.46,
            ...$extra,
        ]);
        $order->items()->create(['product_id' => $produto->id, 'product_name' => 'Parafusadeira 48V com 2 baterias', 'product_price' => 150, 'quantity' => 1, 'subtotal' => 150]);
        $order->items()->create(['product_id' => $outro->id, 'product_name' => 'Jogo de brocas', 'product_price' => 22.46, 'quantity' => 1, 'subtotal' => 22.46]);
        $order->payments()->create(['provider' => Payment::PROVIDER_MERCADOPAGO, 'method_type' => Payment::METHOD_PIX, 'amount' => 172.46, 'status' => Payment::STATUS_CAPTURED]);

        return $order;
    }

    // ---------- Inscrição ----------

    public function test_admin_inscreve_o_aparelho_e_reinscrever_so_atualiza(): void
    {
        $admin = $this->admin();

        $this->inscrever($admin, 'kazakora', self::ENDPOINT_KZ);
        $this->inscrever($admin, 'kazakora', self::ENDPOINT_KZ);

        $this->assertSame(1, PushInscricao::count());
        $inscricao = PushInscricao::first();
        $this->assertSame('kazakora', $inscricao->app);
        $this->assertSame($admin->id, $inscricao->user_id);
        $this->assertNotNull($inscricao->ultima_vez_em);

        $this->actingAs($admin)->getJson('/admin/vendas-app/kazakora/estado')
            ->assertOk()
            ->assertJson(['app' => 'kazakora', 'configurado' => true, 'aparelhos' => 1, 'chave_publica' => config('services.webpush.public_key')]);

        $this->actingAs($admin)->postJson('/admin/vendas-app/kazakora/cancelar', ['endpoint' => self::ENDPOINT_KZ])->assertOk();
        $this->assertSame(0, PushInscricao::count());
    }

    public function test_quem_nao_e_admin_nao_inscreve_e_app_desconhecido_nao_existe(): void
    {
        $gerente = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $this->actingAs($gerente)->postJson('/admin/vendas-app/kazakora/inscrever', $this->inscricao(self::ENDPOINT_KZ))->assertForbidden();

        $this->actingAs($this->admin())->postJson('/admin/vendas-app/beautykora/inscrever', $this->inscricao(self::ENDPOINT_KZ))->assertNotFound();
        $this->assertSame(0, PushInscricao::count());
    }

    public function test_chaves_invalidas_sao_recusadas(): void
    {
        $this->actingAs($this->admin())->postJson('/admin/vendas-app/kazakora/inscrever', [
            'endpoint' => self::ENDPOINT_KZ, 'keys' => ['p256dh' => 'abc', 'auth' => 'def'],
        ])->assertStatus(422);

        $this->assertSame(0, PushInscricao::count());
    }

    public function test_deslogado_vai_para_o_login(): void
    {
        $this->get('/admin/vendas-app/kazakora/entrar')->assertRedirect();
        $this->actingAs($this->admin())->get('/admin/vendas-app/kazakora/entrar')->assertRedirect('/app/kazakora/');

        // Vindo do POST de login do Inertia: troca de página inteira.
        $versao = (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request());
        $this->actingAs($this->admin())->get('/admin/vendas-app/ferrakora/entrar', ['X-Inertia' => 'true', 'X-Inertia-Version' => $versao])
            ->assertStatus(409)->assertHeader('X-Inertia-Location', '/app/ferrakora/');
    }

    public function test_enviar_teste_manda_so_para_o_app_e_apaga_inscricao_morta(): void
    {
        Http::fake([
            'fcm.googleapis.com/*' => Http::response('', 201),
            'web.push.apple.com/*' => Http::response('', 410),
        ]);
        $admin = $this->admin();
        $this->inscrever($admin, 'kazakora', self::ENDPOINT_KZ);
        $this->inscrever($admin, 'ferrakora', self::ENDPOINT_FK);

        $this->actingAs($admin)->postJson('/admin/vendas-app/kazakora/teste')
            ->assertOk()->assertJson(['enviados' => 1, 'falhas' => 0, 'removidas' => 0]);

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::ENDPOINT_KZ
            && $r->header('Content-Encoding')[0] === 'aes128gcm'
            && str_starts_with($r->header('Authorization')[0], 'vapid t=')
            && str_contains($r->header('Authorization')[0], 'k='.config('services.webpush.public_key')));

        // FerraKora: o serviço diz que a inscrição morreu (410) e ela some.
        $this->actingAs($admin)->postJson('/admin/vendas-app/ferrakora/teste')
            ->assertStatus(422)->assertJson(['enviados' => 0, 'removidas' => 1]);
        $this->assertSame(['kazakora'], PushInscricao::pluck('app')->all());
    }

    // ---------- Aviso de venda ----------

    public function test_pagamento_confirmado_da_kazakora_poe_o_aviso_na_fila(): void
    {
        Queue::fake();
        $order = $this->pedido();

        $this->assertTrue(app(OrderPaymentFinalizer::class)->finalize($order->fresh()));
        app(OrderPaymentFinalizer::class)->finalize($order->fresh());

        Queue::assertPushed(AvisarVendaPorPushJob::class, 1);
        Queue::assertPushed(AvisarVendaPorPushJob::class, fn ($job) => $job->orderId === $order->id);
    }

    public function test_aviso_da_kazakora_vai_so_para_o_app_kazakora_e_uma_vez_so(): void
    {
        Http::fake(['*' => Http::response('', 201)]);
        $admin = $this->admin();
        $this->inscrever($admin, 'kazakora', self::ENDPOINT_KZ);
        $this->inscrever($admin, 'ferrakora', self::ENDPOINT_FK);
        $order = $this->pedido();
        $order->update(['status' => Order::STATUS_PAID]);

        (new AvisarVendaPorPushJob($order->id))->handle(app(AvisosPush::class));
        (new AvisarVendaPorPushJob($order->id))->handle(app(AvisosPush::class));

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::ENDPOINT_KZ);
        $this->assertDatabaseHas('push_vendas_avisadas', ['order_id' => $order->id, 'app' => 'kazakora', 'enviados' => 1]);
    }

    public function test_aviso_da_ferrakora_vai_so_para_o_app_ferrakora(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('', 201)]);
        $admin = $this->admin();
        $this->inscrever($admin, 'kazakora', self::ENDPOINT_KZ);
        $this->inscrever($admin, 'ferrakora', self::ENDPOINT_FK);
        $order = $this->pedido(['site_origem' => Order::SITE_FERRAKORA]);

        app(OrderPaymentFinalizer::class)->finalize($order->fresh());
        Queue::assertPushed(AvisarVendaPorPushJob::class, 1);

        (new AvisarVendaPorPushJob($order->id))->handle(app(AvisosPush::class));
        (new AvisarVendaPorPushJob($order->id))->handle(app(AvisosPush::class));

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === self::ENDPOINT_FK);
    }

    public function test_texto_do_aviso_tem_produto_numero_e_valor_e_nenhum_dado_do_cliente(): void
    {
        $order = $this->pedido();

        $mensagem = app(AvisosPush::class)->mensagemDaVenda($order, 'kazakora');

        $this->assertSame('Vendeu: Parafusadeira 48V com 2 baterias e mais 1', $mensagem['title']);
        $this->assertSame('Pedido #'.$order->id.' · R$ 172,46', $mensagem['body']);
        $this->assertSame('https://app.kazakora.com/icons/icon-192.png', $mensagem['icon']);
        $this->assertSame('https://app.kazakora.com/icons/badge-96.png', $mensagem['badge']);
        $this->assertSame('/#/pedidos/'.$order->id, $mensagem['url']);

        $texto = json_encode($mensagem, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Maria', $texto);
        $this->assertStringNotContainsString('Secreta', $texto);
        $this->assertStringNotContainsString('98888', $texto);
    }

    public function test_pedido_de_marketplace_ou_beauty_kora_nao_avisa(): void
    {
        Queue::fake();
        $ml = $this->pedido(['origin' => 'mercado_livre']);
        $beauty = $this->pedido(['site_origem' => Order::SITE_BEAUTYKORA]);

        app(OrderPaymentFinalizer::class)->finalize($ml->fresh());
        app(OrderPaymentFinalizer::class)->finalize($beauty->fresh());

        Queue::assertNotPushed(AvisarVendaPorPushJob::class);

        // E o job, se rodar mesmo assim, não manda.
        Http::fake();
        $admin = $this->admin();
        $this->inscrever($admin, 'kazakora', self::ENDPOINT_KZ);
        $ml->update(['status' => Order::STATUS_PAID]);
        (new AvisarVendaPorPushJob($ml->id))->handle(app(AvisosPush::class));
        Http::assertNothingSent();
    }

    public function test_pedido_ainda_pendente_nao_avisa(): void
    {
        Queue::fake();
        $order = $this->pedido();
        $order->payments()->update(['status' => Payment::STATUS_REQUIRES_CONFIRMATION]);

        $this->assertFalse(app(OrderPaymentFinalizer::class)->finalize($order->fresh()));
        Queue::assertNotPushed(AvisarVendaPorPushJob::class);

        Http::fake();
        $this->inscrever($this->admin(), 'kazakora', self::ENDPOINT_KZ);
        (new AvisarVendaPorPushJob($order->id))->handle(app(AvisosPush::class));
        Http::assertNothingSent();
        $this->assertDatabaseCount('push_vendas_avisadas', 0);
    }

    public function test_falha_no_servico_de_push_nao_quebra_nada(): void
    {
        Http::fake(['*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('fora do ar')]);
        $this->inscrever($this->admin(), 'kazakora', self::ENDPOINT_KZ);
        $order = $this->pedido();
        $order->update(['status' => Order::STATUS_PAID]);

        (new AvisarVendaPorPushJob($order->id))->handle(app(AvisosPush::class));

        $this->assertSame(1, PushInscricao::first()->falhas);
    }
}
