<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Mail\CupomPromocional;
use App\Modules\Checkout\Models\Coupon;
use App\Modules\Checkout\Models\CouponDisparo;
use App\Modules\Checkout\Models\Order;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Cupons completos (pedido 2026-10-10): aplicação reativa no checkout com as
 * regras do cupom, cadastro no admin e disparo em lote.
 */
class CupomCompletoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
    }

    private function carrinho(float $preco = 200): void
    {
        $product = Product::factory()->create(['price' => $preco, 'stock' => 10, 'is_active' => true]);
        $this->post('/carrinho', ['product_id' => $product->id, 'quantity' => 1]);
    }

    private function pedidoPago(string $codigo, array $extra = []): Order
    {
        return Order::create(array_merge([
            'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_STORE, 'coupon_code' => $codigo,
            'shipping_name' => 'X', 'shipping_phone' => '11999990000', 'shipping_zip' => '01000000', 'shipping_street' => 'Rua',
            'shipping_number' => '1', 'shipping_neighborhood' => 'Centro', 'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 100, 'shipping_cost' => 0, 'total' => 90,
        ], $extra));
    }

    public function test_aplica_por_json_sem_diferenciar_maiusculas_e_remove(): void
    {
        Coupon::create(['code' => 'volta10', 'discount_type' => Coupon::TYPE_PERCENTAGE, 'discount_value' => 10, 'is_active' => true]);
        $this->carrinho(200);

        $this->postJson('/finalizacao/cupom', ['code' => ' Volta10 '])
            ->assertOk()
            ->assertJson(['code' => 'VOLTA10', 'discount_amount' => 20, 'descricao' => '10% de desconto']);
        $this->assertSame('VOLTA10', session('checkout_draft.coupon_code'));

        $this->get('/finalizacao')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('couponCode', 'VOLTA10')->where('discountAmount', 20));

        $this->deleteJson('/finalizacao/cupom')->assertOk();
        $this->assertNull(session('checkout_draft.coupon_code'));
    }

    public function test_regras_do_cupom_recusam_com_mensagem(): void
    {
        $this->carrinho(100);
        Coupon::create(['code' => 'VENCIDO', 'discount_type' => 'fixed', 'discount_value' => 10, 'is_active' => true, 'expires_at' => now()->subDay()]);
        Coupon::create(['code' => 'MINIMO', 'discount_type' => 'fixed', 'discount_value' => 10, 'is_active' => true, 'min_order_value' => 150]);
        Coupon::create(['code' => 'ESGOTADO', 'discount_type' => 'fixed', 'discount_value' => 10, 'is_active' => true, 'max_uses' => 1]);
        $this->pedidoPago('ESGOTADO');

        $this->postJson('/finalizacao/cupom', ['code' => 'NAOEXISTE'])->assertStatus(422)->assertJsonPath('message', 'Cupom não encontrado. Confira o código.');
        $this->postJson('/finalizacao/cupom', ['code' => 'vencido'])->assertStatus(422)->assertJsonFragment(['message' => 'Este cupom expirou em '.now()->subDay()->format('d/m/Y').'.']);
        $this->postJson('/finalizacao/cupom', ['code' => 'minimo'])->assertStatus(422)->assertJsonFragment(['message' => 'Este cupom vale para compras a partir de R$ 150,00.']);
        $this->postJson('/finalizacao/cupom', ['code' => 'esgotado'])->assertStatus(422)->assertJsonFragment(['message' => 'Este cupom já atingiu o limite de usos.']);
    }

    public function test_um_por_cliente(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        Coupon::create(['code' => 'PRIMEIRA', 'discount_type' => 'fixed', 'discount_value' => 10, 'is_active' => true, 'one_per_customer' => true]);
        $this->pedidoPago('PRIMEIRA', ['user_id' => $user->id]);
        $this->actingAs($user);
        $this->carrinho(100);

        $this->postJson('/finalizacao/cupom', ['code' => 'primeira'])->assertStatus(422)->assertJsonFragment(['message' => 'Você já usou este cupom em outra compra.']);
    }

    public function test_link_com_cupom_guarda_no_checkout(): void
    {
        Coupon::create(['code' => 'LINK15', 'discount_type' => 'percentage', 'discount_value' => 15, 'is_active' => true]);

        $this->get('/?cupom=link15')->assertOk();

        $this->assertSame('LINK15', session('checkout_draft.coupon_code'));
    }

    public function test_admin_cria_cupom_e_dispara_para_carrinho_abandonado(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->post('/admin/cupons', [
            'code' => 'volta 10', 'discount_type' => 'percentage', 'discount_value' => 10, 'one_per_customer' => true, 'is_active' => true,
            'expires_at' => now()->addWeek()->toDateString(),
        ])->assertSessionHasNoErrors();
        $coupon = Coupon::query()->where('code', 'VOLTA10')->firstOrFail();
        $this->assertTrue($coupon->expires_at->isSameDay(now()->addWeek()));

        // Abandonou (pedido aguardando pagamento) x comprou x não quer promoções.
        $abandonou = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'name' => 'Ana Souza']);
        $this->pedidoPago('', ['user_id' => $abandonou->id, 'status' => Order::STATUS_AWAITING_PAYMENT, 'coupon_code' => null]);
        $comprou = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->pedidoPago('', ['user_id' => $comprou->id, 'coupon_code' => null]);
        $saiu = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $saiu->forceFill(['recebe_promocoes' => false])->save();
        $this->pedidoPago('', ['user_id' => $saiu->id, 'status' => Order::STATUS_CANCELLED, 'coupon_code' => null]);

        $this->getJson("/admin/cupons/{$coupon->id}/publico?publico=carrinho_abandonado&dias=30")->assertJson(['total' => 1]);
        $this->getJson("/admin/cupons/{$coupon->id}/publico?publico=clientes_todos")->assertJson(['total' => 2]);

        Mail::fake();
        $this->post("/admin/cupons/{$coupon->id}/disparo", [
            'publico' => 'carrinho_abandonado', 'dias' => 30, 'ocasiao' => 'carrinho',
            'assunto' => '{nome}, seu carrinho', 'mensagem' => 'Use {cupom} e ganhe {desconto}.', 'canais' => ['email', 'site'],
        ])->assertSessionHasNoErrors();

        $disparo = CouponDisparo::query()->firstOrFail();
        $this->assertSame(CouponDisparo::STATUS_CONCLUIDO, $disparo->status);
        $this->assertSame([1, 1, 0], [$disparo->total, $disparo->enviados, $disparo->falhas]);
        Mail::assertSent(CupomPromocional::class, fn (CupomPromocional $mail) => $mail->hasTo($abandonou->email)
            && $mail->assunto === 'Ana, seu carrinho'
            && $mail->mensagem === 'Use VOLTA10 e ganhe 10% de desconto.');
        Mail::assertSent(CupomPromocional::class, 1);
        $this->assertSame(1, $abandonou->notifications()->count());
    }

    public function test_cliente_sai_das_promocoes_pelo_link_assinado(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        $this->get('/promocoes/sair/'.$user->id)->assertForbidden();
        $this->get(URL::signedRoute('promocoes.sair', ['user' => $user->id]))->assertRedirect('/');

        $this->assertFalse((bool) $user->fresh()->recebe_promocoes);
    }

    public function test_cupom_usado_nao_pode_ser_excluido(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $coupon = Coupon::create(['code' => 'USADO', 'discount_type' => 'fixed', 'discount_value' => 5, 'is_active' => true]);
        $this->pedidoPago('USADO');

        $this->actingAs($admin)->delete("/admin/cupons/{$coupon->id}")->assertSessionHasErrors('cupom');
        $this->assertModelExists($coupon);
        $this->get('/admin/cupons')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Cupons/Index', false)
            ->where('cupons.0.usos', 1));
    }
}
