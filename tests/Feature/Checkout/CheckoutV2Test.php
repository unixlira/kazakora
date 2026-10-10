<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Auth\Mail\WelcomeEmail;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Coupon;
use App\Modules\Checkout\Models\Order;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Checkout v2 (pedido 2026-10-10): tela única de fechamento. Grava a entrega
 * por JSON e cria o pagamento na mesma página; quem compra sem conta ganha
 * conta com senha temporária enviada no e-mail de boas-vindas.
 */
class CheckoutV2Test extends TestCase
{
    use RefreshDatabase;

    private ShippingMethod $frete;

    protected function setUp(): void
    {
        parent::setUp();
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        $this->frete = ShippingMethod::factory()->create(['price' => 0, 'is_active' => true]);
    }

    private function entrega(array $extra = []): array
    {
        return array_merge([
            'shipping_method_id' => $this->frete->id,
            'new_address' => [
                'recipient_name' => 'Maria Cliente', 'phone' => '(11) 99999-0000', 'zip' => '03187-040',
                'street' => 'Rua Mogi Mirim', 'number' => '20', 'neighborhood' => 'Mooca', 'city' => 'São Paulo', 'state' => 'SP',
            ],
        ], $extra);
    }

    private function noCarrinho(float $preco = 105): Product
    {
        $product = Product::factory()->create(['price' => $preco, 'stock' => 5, 'is_active' => true]);
        $this->post('/carrinho', ['product_id' => $product->id, 'quantity' => 1]);

        return $product;
    }

    public function test_tela_unica_preenche_os_dados_do_cliente_logado(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'name' => 'Ana Logada', 'phone' => '11988887777', 'cpf' => '12345678909']);
        $user->addresses()->create(['label' => 'Casa', 'recipient_name' => 'Ana', 'phone' => '11988887777', 'zip' => '01001-000', 'street' => 'Praça da Sé', 'number' => '1', 'neighborhood' => 'Sé', 'city' => 'São Paulo', 'state' => 'SP']);
        $this->actingAs($user);
        $this->noCarrinho();

        $this->get('/finalizacao')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Checkout/CheckoutV2', false)
                ->where('customer.name', 'Ana Logada')
                ->where('customer.phone', '11988887777')
                ->has('addresses', 1)
                ->where('pixDiscountPercentage', 5));
    }

    public function test_v1_continua_disponivel(): void
    {
        $this->noCarrinho();

        $this->get('/finalizacao?v=1')->assertInertia(fn (AssertableInertia $page) => $page->component('Checkout/Delivery', false));
    }

    public function test_entrega_por_json_valida_e_grava_o_rascunho(): void
    {
        $this->noCarrinho();

        $this->postJson('/finalizacao/entrega', ['shipping_method_id' => $this->frete->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['guest', 'new_address.zip']);

        $this->postJson('/finalizacao/entrega', $this->entrega(['guest' => ['name' => 'Maria Cliente', 'email' => 'maria@exemplo.com', 'cpf' => '123.456.789-09', 'phone' => '(11) 99999-0000']]))
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame('maria@exemplo.com', session('checkout_draft.guest.email'));
    }

    public function test_entrega_e_sempre_frete_gratis_mesmo_mandando_outro(): void
    {
        $this->noCarrinho();
        $pago = ShippingMethod::factory()->create(['price' => 30, 'is_active' => true]);

        $this->get('/finalizacao')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('shippingMethods', 1)
            ->where('shippingMethods.0.name', 'Frete Grátis')
            ->where('shippingMethods.0.estimated_days', 7));

        $this->postJson('/finalizacao/entrega', $this->entrega([
            'shipping_method_id' => 'correios:03220',
            'shipping_quote' => ['name' => 'SEDEX', 'price' => 30.06],
            'guest' => ['name' => 'Maria Cliente', 'email' => 'maria@exemplo.com', 'cpf' => '123.456.789-09', 'phone' => '(11) 99999-0000'],
        ]))->assertOk();

        $gratis = ShippingMethod::query()->where('name', 'Frete Grátis')->first();
        $this->assertSame($gratis->id, (int) session('checkout_draft.shipping_method_id'));
        $this->assertNotSame($pago->id, $gratis->id);
        $this->assertEmpty(session('checkout_draft.shipping_quote'));
    }

    public function test_nome_curto_e_cpf_invalido_sao_recusados(): void
    {
        $this->noCarrinho();

        $this->postJson('/finalizacao/entrega', $this->entrega(['guest' => ['name' => '  Ana Souza ', 'email' => 'ana@exemplo.com', 'cpf' => '111.111.111-11']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['guest.name', 'guest.cpf']);

        $this->postJson('/finalizacao/entrega', $this->entrega(['guest' => ['name' => 'Ana Souza', 'email' => 'ana@exemplo.com', 'cpf' => '123.456.789-01']]))
            ->assertStatus(422)
            ->assertJsonPath('errors', fn ($erros) => str_contains($erros['guest.cpf'][0], 'CPF inválido') && str_contains($erros['guest.name'][0], 'mais de 10'));

        $this->postJson('/finalizacao/entrega', $this->entrega(['guest' => ['name' => 'Ana Souza Lima', 'email' => 'ana@exemplo.com', 'cpf' => '123.456.789-09']]))
            ->assertOk();
    }

    public function test_email_que_ja_tem_conta_pede_login(): void
    {
        User::factory()->create(['email' => 'ja@exemplo.com']);
        $this->noCarrinho();

        $this->postJson('/finalizacao/entrega', $this->entrega(['guest' => ['name' => 'Xavier da Silva', 'email' => 'ja@exemplo.com', 'cpf' => '123.456.789-09']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['guest.email' => 'Já existe uma conta com esse e-mail. Faça login para continuar.']);
    }

    public function test_cupom_aplicado_antes_da_entrega_nao_se_perde(): void
    {
        Coupon::create(['code' => 'DEZOFF', 'discount_type' => Coupon::TYPE_PERCENTAGE, 'discount_value' => 10, 'is_active' => true]);
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->actingAs($user);
        $this->noCarrinho(200);

        $this->post('/finalizacao/pagamento/cupom', ['code' => 'DEZOFF'])->assertRedirect(route('finalizacao.entrega'));
        $this->postJson('/finalizacao/entrega', $this->entrega())->assertOk();

        $this->assertSame('DEZOFF', session('checkout_draft.coupon_code'));
        $this->get('/finalizacao')->assertInertia(fn (AssertableInertia $page) => $page->where('discountAmount', 20));
    }

    public function test_convidado_paga_no_pix_ganha_conta_e_senha_temporaria_no_email(): void
    {
        Mail::fake();
        $this->noCarrinho(105);

        $this->mock(MercadoPagoPaymentService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createPixPayment')->once()->andReturn([
                'id' => 77, 'status' => 'pending',
                'point_of_interaction' => ['transaction_data' => ['qr_code' => 'pix-copia-e-cola', 'qr_code_base64' => 'abc']],
            ]);
        });

        $this->postJson('/finalizacao/entrega', $this->entrega(['guest' => ['name' => 'Maria Cliente', 'email' => 'maria@exemplo.com', 'cpf' => '123.456.789-09', 'phone' => '(11) 99999-0000']]))->assertOk();

        $this->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true])
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Checkout/CheckoutV2', false)
                ->where('mercadoPagoPix.qrCode', 'pix-copia-e-cola')
                ->has('order.id'));

        $user = User::where('email', 'maria@exemplo.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('(11) 99999-0000', $user->phone);
        $this->assertEquals(5.25, (float) Order::firstOrFail()->pix_discount_amount);

        Mail::assertSent(WelcomeEmail::class, function (WelcomeEmail $mail) use ($user) {
            return $mail->hasTo('maria@exemplo.com')
                && $mail->senhaTemporaria !== null
                && Hash::check($mail->senhaTemporaria, $user->password);
        });
    }

    public function test_cadastro_normal_nao_manda_senha(): void
    {
        Mail::fake();

        $this->post('/cadastro', [
            'name' => 'Pedro', 'email' => 'pedro@exemplo.com', 'password' => 'SenhaForte123', 'password_confirmation' => 'SenhaForte123',
        ]);

        Mail::assertSent(WelcomeEmail::class, fn (WelcomeEmail $mail) => $mail->senhaTemporaria === null);
    }
}
