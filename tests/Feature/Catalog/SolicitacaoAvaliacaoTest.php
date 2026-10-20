<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Mail\PedidoAvaliacao;
use App\Modules\Catalog\Models\AvaliacaoSolicitacao;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use App\Modules\Catalog\Support\SolicitacaoAvaliacao;
use App\Modules\Checkout\Models\Coupon;
use App\Modules\Checkout\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pedido de avaliação pós-compra (pedido do Lira 2026-10-18): pedido do
 * kazakora.com entregue há N dias recebe um e-mail, uma vez, que leva à
 * página própria de avaliação; o cupom nasce lá, pelo desconto da nota.
 */
class SolicitacaoAvaliacaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00', 'America/Sao_Paulo'));
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ligar(array $extra = []): void
    {
        Carbon::setTestNow(now()->subDays(10));
        SolicitacaoAvaliacao::salvar(['ativo' => true, 'dias_apos_entrega' => 3, ...$extra]);
        Carbon::setTestNow(now()->addDays(10));
    }

    private function pedido(array $dados = [], ?User $user = null, ?Product $product = null): Order
    {
        $user ??= User::factory()->create(['role' => User::ROLE_CUSTOMER, 'email' => 'cliente@example.com', 'name' => 'MARIA SILVA']);
        $product ??= Product::factory()->create(['is_active' => true]);

        $order = Order::create([
            'user_id' => $user->id,
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_STORE,
            'shipping_name' => $user->name,
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X',
            'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 100,
            'total' => 100,
            ...$dados,
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_price' => 100,
            'quantity' => 1,
            'subtotal' => 100,
        ]);

        return $order;
    }

    private function entregarHa(Order $order, int $dias): void
    {
        $order->update(['status' => Order::STATUS_COMPLETED]);
        $order->forceFill(['entregue_em' => now()->subDays($dias)])->save();
    }

    public function test_concluir_o_pedido_grava_quando_foi_entregue(): void
    {
        $order = $this->pedido();
        $this->assertNull($order->entregue_em);

        $order->update(['status' => Order::STATUS_COMPLETED]);

        $this->assertNotNull($order->fresh()->entregue_em);
    }

    public function test_desligada_nao_envia(): void
    {
        $this->entregarHa($this->pedido(), 5);

        $this->artisan('avaliacoes:solicitar')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertSame(0, AvaliacaoSolicitacao::count());
    }

    public function test_envia_uma_vez_depois_dos_dias_com_link_da_pagina_e_sem_cupom(): void
    {
        $this->ligar();
        $cedo = $this->pedido();
        $this->entregarHa($cedo, 1);
        $naHora = $this->pedido([], User::factory()->create(['email' => 'outro@example.com']));
        $this->entregarHa($naHora, 4);

        $this->artisan('avaliacoes:solicitar')->assertSuccessful();
        $this->artisan('avaliacoes:solicitar')->assertSuccessful();

        Mail::assertSent(PedidoAvaliacao::class, 1);
        $solicitacao = AvaliacaoSolicitacao::sole();
        Mail::assertSent(PedidoAvaliacao::class, fn (PedidoAvaliacao $mail) => $mail->hasTo('outro@example.com')
            && $mail->order->is($naHora)
            && $mail->link === route('avaliacoes.pedido', $solicitacao->token));

        $this->assertSame(AvaliacaoSolicitacao::STATUS_ENVIADO, $solicitacao->status);
        $this->assertNull($solicitacao->coupon_id);
        $this->assertSame(0, Coupon::count());

        $html = (new PedidoAvaliacao($naHora, SolicitacaoAvaliacao::produtosParaAvaliar($naHora), $solicitacao->link(), SolicitacaoAvaliacao::config()))->render();
        $this->assertStringContainsString('/avaliar-pedido/'.$solicitacao->token.'?produto=', $html);
        $this->assertStringNotContainsString('/produtos/', $html);
        $this->assertStringContainsString('10% de desconto', $html);
    }

    /** Pedido entregue com o e-mail já enviado; devolve a solicitação. */
    private function enviado(?Product $produto = null): AvaliacaoSolicitacao
    {
        $this->ligar();
        $this->entregarHa($this->pedido([], null, $produto), 4);
        $this->artisan('avaliacoes:solicitar')->assertSuccessful();

        return AvaliacaoSolicitacao::sole();
    }

    public function test_pagina_abre_pelo_token_sem_login(): void
    {
        $solicitacao = $this->enviado();

        $this->get("/avaliar-pedido/{$solicitacao->token}?nota=5")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Catalog/AvaliarPedido', false)
                ->where('pedido', (string) $solicitacao->order->numero)
                ->where('nome', 'Maria')
                ->has('produtos', 1)
                ->where('tabela', [1, 2, 3, 4, 10])
                ->where('inicial.nota', 5)
                ->where('resultado', null));

        $this->get('/avaliar-pedido/'.str_repeat('x', 48))->assertNotFound();
    }

    public static function notas(): array
    {
        return [[1, 1.0], [2, 2.0], [3, 3.0], [4, 4.0], [5, 10.0]];
    }

    #[DataProvider('notas')]
    public function test_cupom_pela_nota(int $nota, float $percentual): void
    {
        $produto = Product::factory()->create(['is_active' => true]);
        $solicitacao = $this->enviado($produto);

        $this->post("/avaliar-pedido/{$solicitacao->token}", ['avaliacoes' => [['product_id' => $produto->id, 'rating' => $nota, 'comment' => 'Chegou certinho']]])
            ->assertRedirect(route('avaliacoes.pedido', $solicitacao->token));

        $solicitacao->refresh();
        $this->assertSame($nota, $solicitacao->nota);
        $this->assertNotNull($solicitacao->avaliado_em);
        $cupom = $solicitacao->coupon;
        $this->assertSame(Coupon::TYPE_PERCENTAGE, $cupom->discount_type);
        $this->assertEquals($percentual, (float) $cupom->discount_value);
        $this->assertSame(1, $cupom->max_uses);
        $this->assertSame(1, $cupom->limitePorCliente());
        $this->assertTrue($cupom->expires_at->isSameDay(now()->addDays(30)));
        $this->assertTrue(str_starts_with($cupom->code, 'AVALIE'));

        $review = Review::sole();
        $this->assertSame($nota, $review->rating);
        $this->assertSame('Chegou certinho', $review->comment);
        $this->assertSame($solicitacao->user_id, $review->user_id);

        $this->get("/avaliar-pedido/{$solicitacao->token}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('resultado.nota', $nota)
                ->where('resultado.cupom.codigo', $cupom->code));
    }

    public function test_avalia_uma_vez_so(): void
    {
        $produto = Product::factory()->create(['is_active' => true]);
        $solicitacao = $this->enviado($produto);

        $this->post("/avaliar-pedido/{$solicitacao->token}", ['avaliacoes' => [['product_id' => $produto->id, 'rating' => 2]]]);
        $this->post("/avaliar-pedido/{$solicitacao->token}", ['avaliacoes' => [['product_id' => $produto->id, 'rating' => 5]]]);

        $this->assertSame(1, Coupon::count());
        $this->assertEquals(2, (float) Coupon::sole()->discount_value);
        $this->assertSame(2, Review::sole()->rating);
    }

    public function test_pedido_com_varios_produtos_usa_a_media_e_ignora_produto_de_fora(): void
    {
        $produto = Product::factory()->create(['is_active' => true]);
        $solicitacao = $this->enviado($produto);
        $outro = Product::factory()->create(['is_active' => true]);
        $solicitacao->order->items()->create(['product_id' => $outro->id, 'product_name' => $outro->name, 'product_price' => 50, 'quantity' => 1, 'subtotal' => 50]);
        $deFora = Product::factory()->create(['is_active' => true]);

        $this->post("/avaliar-pedido/{$solicitacao->token}", ['avaliacoes' => [
            ['product_id' => $produto->id, 'rating' => 5],
            ['product_id' => $outro->id, 'rating' => 4],
            ['product_id' => $deFora->id, 'rating' => 1],
        ]]);

        $this->assertSame(5, $solicitacao->fresh()->nota);
        $this->assertEquals(10, (float) Coupon::sole()->discount_value);
        $this->assertSame(2, Review::count());
        $this->assertFalse(Review::where('product_id', $deFora->id)->exists());
    }

    public function test_nota_invalida_nao_gera_cupom(): void
    {
        $produto = Product::factory()->create(['is_active' => true]);
        $solicitacao = $this->enviado($produto);

        $this->post("/avaliar-pedido/{$solicitacao->token}", ['avaliacoes' => [['product_id' => $produto->id, 'rating' => 6]]])
            ->assertSessionHasErrors('avaliacoes.0.rating');

        $this->assertSame(0, Coupon::count());
        $this->assertNull($solicitacao->fresh()->nota);
    }

    public function test_pedido_pulado_nao_abre_a_pagina(): void
    {
        $this->ligar();
        $this->entregarHa($this->pedido([], User::factory()->create(['recebe_promocoes' => false])), 4);
        $this->artisan('avaliacoes:solicitar')->assertSuccessful();

        $this->get('/avaliar-pedido/'.AvaliacaoSolicitacao::sole()->token)->assertNotFound();
    }

    public function test_pedido_entregue_antes_de_ligar_nao_recebe(): void
    {
        $order = $this->pedido();
        $this->entregarHa($order, 12);
        $this->ligar();

        $this->artisan('avaliacoes:solicitar')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_marketplace_e_outros_sites_ficam_de_fora(): void
    {
        $this->ligar();
        $this->entregarHa($this->pedido(['origin' => Order::ORIGIN_SHOPEE]), 4);
        $this->entregarHa($this->pedido(['site_origem' => Order::SITE_FERRAKORA], User::factory()->create()), 4);

        $this->artisan('avaliacoes:solicitar')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_quem_saiu_das_promocoes_ou_ja_avaliou_nao_recebe(): void
    {
        $this->ligar();
        $saiu = User::factory()->create(['recebe_promocoes' => false]);
        $this->entregarHa($this->pedido([], $saiu), 4);

        $avaliou = User::factory()->create();
        $produto = Product::factory()->create(['is_active' => true]);
        Review::create(['user_id' => $avaliou->id, 'product_id' => $produto->id, 'rating' => 5]);
        $this->entregarHa($this->pedido([], $avaliou, $produto), 4);

        $this->artisan('avaliacoes:solicitar')->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertSame(2, AvaliacaoSolicitacao::where('status', AvaliacaoSolicitacao::STATUS_PULADO)->count());
    }

    public function test_fora_do_horario_nao_envia(): void
    {
        $this->ligar();
        $this->entregarHa($this->pedido(), 4);
        Carbon::setTestNow(Carbon::parse('2026-10-20 22:30', 'America/Sao_Paulo'));

        $this->artisan('avaliacoes:solicitar')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_avaliar_marca_a_solicitacao(): void
    {
        $this->ligar();
        $produto = Product::factory()->create(['is_active' => true]);
        $order = $this->pedido([], null, $produto);
        $this->entregarHa($order, 4);
        $this->artisan('avaliacoes:solicitar')->assertSuccessful();

        $this->actingAs($order->user)->post("/produtos/{$produto->id}/avaliacoes", ['rating' => 4, 'comment' => 'Bom'])
            ->assertSessionHasNoErrors();

        $this->assertNotNull(AvaliacaoSolicitacao::sole()->avaliado_em);
    }

    public function test_link_avaliar_exige_login_e_abre_a_janela(): void
    {
        $produto = Product::factory()->create(['is_active' => true]);

        $this->get("/avaliar/{$produto->slug}")->assertRedirect(route('entrar'));
        $this->actingAs(User::factory()->create())->get("/avaliar/{$produto->slug}")
            ->assertRedirect(route('produtos.ver', $produto->slug).'?avaliar=1#avaliacoes');
    }

    public function test_admin_salva_e_ve_a_previa(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $dados = [...SolicitacaoAvaliacao::PADRAO, 'ativo' => true, 'cupom_por_estrela' => [1, 2, 3, 5, 12], 'assunto' => 'Oi {nome}, avalie o pedido {pedido}'];

        $this->actingAs($admin)->putJson('/admin/avaliacoes/pos-compra', $dados)
            ->assertOk()
            ->assertJsonPath('config.ativo', true)
            ->assertJsonPath('config.assunto', 'Oi {nome}, avalie o pedido {pedido}')
            ->assertJsonPath('config.cupom_por_estrela', [1, 2, 3, 5, 12]);
        $this->assertNotNull(SolicitacaoAvaliacao::ativadoEm());

        $html = $this->actingAs($admin)->postJson('/admin/avaliacoes/pos-compra/previa', $dados)
            ->assertOk()
            ->assertJsonPath('assunto', 'Oi '.explode(' ', $admin->name)[0].', avalie o pedido 261018EXEMPLO')
            ->json('html');
        $this->assertStringContainsString('/avaliar-pedido/', $html);
        $this->assertStringContainsString('até 12% de desconto', $html);

        $this->actingAs($admin)->putJson('/admin/avaliacoes/pos-compra', [...$dados, 'cupom_por_estrela' => [1, 2, 3, 4, 80]])
            ->assertStatus(422);
        $this->actingAs($admin)->putJson('/admin/avaliacoes/pos-compra', [...$dados, 'cupom_por_estrela' => [1, 2]])
            ->assertStatus(422);
    }

    public function test_cliente_nao_acessa_o_painel(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CUSTOMER]))
            ->getJson('/admin/avaliacoes/pos-compra')
            ->assertForbidden();
    }
}
