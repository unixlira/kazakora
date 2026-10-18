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
use Tests\TestCase;

/**
 * Pedido de avaliação pós-compra (pedido do Lira 2026-10-18): pedido do
 * kazakora.com entregue há N dias recebe um e-mail com cupom único, uma vez.
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

    public function test_envia_uma_vez_com_cupom_unico_depois_dos_dias(): void
    {
        $this->ligar(['cupom_tipo' => Coupon::TYPE_PERCENTAGE, 'cupom_valor' => 5, 'cupom_validade_dias' => 30]);
        $cedo = $this->pedido();
        $this->entregarHa($cedo, 1);
        $naHora = $this->pedido([], User::factory()->create(['email' => 'outro@example.com']));
        $this->entregarHa($naHora, 4);

        $this->artisan('avaliacoes:solicitar')->assertSuccessful();
        $this->artisan('avaliacoes:solicitar')->assertSuccessful();

        Mail::assertSent(PedidoAvaliacao::class, 1);
        Mail::assertSent(PedidoAvaliacao::class, fn (PedidoAvaliacao $mail) => $mail->hasTo('outro@example.com')
            && $mail->order->is($naHora)
            && str_starts_with($mail->coupon->code, 'AVALIE'));

        $solicitacao = AvaliacaoSolicitacao::sole();
        $this->assertSame(AvaliacaoSolicitacao::STATUS_ENVIADO, $solicitacao->status);
        $cupom = $solicitacao->coupon;
        $this->assertSame(1, $cupom->max_uses);
        $this->assertSame(1, $cupom->limitePorCliente());
        $this->assertEquals(5, (float) $cupom->discount_value);
        $this->assertTrue($cupom->expires_at->isSameDay(now()->addDays(30)));
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
        $dados = [...SolicitacaoAvaliacao::PADRAO, 'ativo' => true, 'cupom_valor' => 10, 'assunto' => 'Oi {nome}, avalie o pedido {pedido}'];

        $this->actingAs($admin)->putJson('/admin/avaliacoes/pos-compra', $dados)
            ->assertOk()
            ->assertJsonPath('config.ativo', true)
            ->assertJsonPath('config.assunto', 'Oi {nome}, avalie o pedido {pedido}');
        $this->assertNotNull(SolicitacaoAvaliacao::ativadoEm());

        $this->actingAs($admin)->postJson('/admin/avaliacoes/pos-compra/previa', $dados)
            ->assertOk()
            ->assertJsonPath('assunto', 'Oi '.explode(' ', $admin->name)[0].', avalie o pedido 261018EXEMPLO')
            ->assertSee('AVALIEEXEMPL');

        $this->actingAs($admin)->putJson('/admin/avaliacoes/pos-compra', [...$dados, 'cupom_valor' => 80])
            ->assertStatus(422);
    }

    public function test_cliente_nao_acessa_o_painel(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CUSTOMER]))
            ->getJson('/admin/avaliacoes/pos-compra')
            ->assertForbidden();
    }
}
