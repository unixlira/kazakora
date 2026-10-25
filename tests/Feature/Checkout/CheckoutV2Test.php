<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Analytics\Models\CheckoutEvento;
use App\Modules\Auth\Mail\WelcomeEmail;
use App\Modules\Catalog\Models\LivroDigital;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use App\Modules\Checkout\Models\Coupon;
use App\Modules\Checkout\Models\Order;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Support\PaymentGateway;
use App\Support\Privacidade\Cookies;
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

    public function test_resumo_traz_a_media_das_avaliacoes_com_as_variacoes(): void
    {
        $product = $this->noCarrinho();
        $variacao = Product::factory()->create(['parent_product_id' => $product->id]);
        foreach ([[$product, 5], [$variacao, 4], [$variacao, 5]] as [$avaliado, $nota]) {
            $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
            Review::create(['user_id' => $cliente->id, 'product_id' => $avaliado->id, 'rating' => $nota, 'comment' => 'Bom']);
        }

        $this->get('/finalizacao')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Checkout/CheckoutV2', false)
                ->where('avaliacaoItens.media', 4.7)
                ->where('avaliacaoItens.total', 3));
    }

    public function test_checkout_guarda_o_estado_da_regiao_de_navegacao_para_o_aviso_de_frete(): void
    {
        $this->noCarrinho();

        $this->app->forgetInstance(\App\Modules\Checkout\Support\FreteEmbutido::class);
        $this->get('/finalizacao?simular_uf=CE')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('ufReferencia', 'CE'));

        // Mesmo depois de o checkout trocar o CEP da navegação, a referência continua a original.
        $this->app->forgetInstance(\App\Modules\Checkout\Support\FreteEmbutido::class);
        $this->withCookie((string) config('frete_embutido.cookie'), '01310100')->get('/finalizacao')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('ufReferencia', 'CE'));
    }

    public function test_resumo_sem_avaliacao_nao_mostra_estrelas(): void
    {
        $this->noCarrinho();

        $this->get('/finalizacao')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('avaliacaoItens', null));
    }

    public function test_resumo_traz_a_miniatura_dos_livros_de_brinde(): void
    {
        $this->noCarrinho();
        foreach (['Mais Saúde', 'Saúde e Vida', 'Sono & Saúde'] as $i => $titulo) {
            LivroDigital::query()->create([
                'titulo' => $titulo, 'arquivo' => "livros-digitais/{$i}.pdf", 'nome_arquivo' => "{$titulo}.pdf",
                'tamanho' => 13, 'ativo' => true, 'todos_produtos' => true,
            ]);
        }

        $this->get('/finalizacao')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('livrosBrinde.quantidade', 3)
                ->where('livrosBrinde.titulos', ['Mais Saúde', 'Saúde e Vida', 'Sono & Saúde'])
                ->where('livrosBrinde.imagem', fn ($imagem) => str_ends_with($imagem, '.webp')));
    }

    public function test_sem_livro_de_brinde_nao_mostra_miniatura(): void
    {
        $this->noCarrinho();

        $this->get('/finalizacao')->assertInertia(fn (AssertableInertia $page) => $page->where('livrosBrinde', null));
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

    // Pedido 2026-10-24: e-mail ou CPF já cadastrado não trava mais o checkout.
    public function test_email_de_conta_sem_cpf_segue_para_o_pagamento(): void
    {
        User::factory()->create(['email' => 'ja@exemplo.com', 'cpf' => null]);
        $this->noCarrinho();

        $this->postJson('/finalizacao/entrega', $this->entrega(['guest' => ['name' => 'Xavier da Silva', 'email' => 'ja@exemplo.com', 'cpf' => '123.456.789-09']]))
            ->assertOk();
    }

    public function test_cpf_de_outra_conta_nao_trava_a_entrega(): void
    {
        User::factory()->create(['email' => 'dono@exemplo.com', 'cpf' => '123.456.789-09']);
        $this->noCarrinho();

        // Mesmo CPF sem máscara e com outro e-mail.
        $this->postJson('/finalizacao/entrega', $this->entrega(['guest' => ['name' => 'Xavier da Silva', 'email' => 'outro@exemplo.com', 'cpf' => '12345678909']]))
            ->assertOk();
    }

    public function test_email_de_conta_com_outro_cpf_ainda_avisa(): void
    {
        // Sem CPF em comum não há conta para vincular, e o e-mail é único.
        User::factory()->create(['email' => 'ja@exemplo.com', 'cpf' => '529.982.247-25']);
        $this->noCarrinho();

        $this->postJson('/finalizacao/entrega', $this->entrega(['guest' => ['name' => 'Xavier da Silva', 'email' => 'ja@exemplo.com', 'cpf' => '123.456.789-09']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['guest.email']);
    }

    public function test_conta_apagada_tambem_segura_cpf_e_email(): void
    {
        User::factory()->create(['email' => 'apagado@exemplo.com', 'cpf' => '123.456.789-09'])->delete();
        $this->noCarrinho();

        $this->postJson('/finalizacao/entrega', $this->entrega(['guest' => ['name' => 'Xavier da Silva', 'email' => 'apagado@exemplo.com', 'cpf' => '529.982.247-25']]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['guest.email']);
    }

    public function test_convidado_com_cpf_ja_cadastrado_compra_na_conta_existente(): void
    {
        Mail::fake();
        $this->noCarrinho(105);
        $dono = User::factory()->create(['name' => 'Dono Original', 'email' => 'dono@exemplo.com', 'cpf' => '12345678909', 'phone' => null]);
        $senha = $dono->password;

        $this->mock(MercadoPagoPaymentService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createPixPayment')->once()->andReturn([
                'id' => 79, 'status' => 'pending',
                'point_of_interaction' => ['transaction_data' => ['qr_code' => 'pix', 'qr_code_base64' => 'abc']],
            ]);
        });

        $this->postJson('/finalizacao/entrega', $this->entrega(['guest' => ['name' => 'Maria Cliente', 'email' => 'maria@exemplo.com', 'cpf' => '123.456.789-09', 'phone' => '(11) 99999-0000']]))->assertOk();
        $this->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true])->assertOk();

        $this->assertGuest();
        $this->assertSame(1, User::count());
        $this->assertSame($dono->id, Order::firstOrFail()->user_id);
        $dono->refresh();
        // Nome, e-mail e senha da conta existente não mudam; telefone vazio é completado.
        $this->assertSame('Dono Original', $dono->name);
        $this->assertSame('dono@exemplo.com', $dono->email);
        $this->assertSame($senha, $dono->password);
        $this->assertSame('(11) 99999-0000', $dono->phone);
        Mail::assertNotSent(WelcomeEmail::class);
    }

    public function test_logado_nao_pode_usar_cpf_de_outra_conta(): void
    {
        User::factory()->create(['cpf' => '123.456.789-09']);
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'cpf' => null]);
        $this->actingAs($user);
        $this->noCarrinho();

        $this->postJson('/finalizacao/entrega', $this->entrega(['cpf' => '12345678909']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cpf']);

        $this->assertNull($user->fresh()->cpf);
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
        // Conta criada, mas sem login automático (pedido do Lira 2026-10-24).
        $this->assertGuest();
        $this->assertSame('(11) 99999-0000', $user->phone);
        // Senha temporária: a loja pede a troca ao entrar.
        $this->assertTrue($user->deve_trocar_senha);
        $this->assertEquals(5.25, (float) Order::firstOrFail()->pix_discount_amount);

        Mail::assertSent(WelcomeEmail::class, function (WelcomeEmail $mail) use ($user) {
            return $mail->hasTo('maria@exemplo.com')
                && $mail->senhaTemporaria !== null
                && Hash::check($mail->senhaTemporaria, $user->password);
        });
    }

    public function test_pedido_criado_entra_na_medicao_com_o_mesmo_visitante(): void
    {
        Mail::fake();
        config(['app.url' => 'http://localhost']);
        $this->noCarrinho(105);
        $this->mock(MercadoPagoPaymentService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createPixPayment')->once()->andReturn([
                'id' => 78, 'status' => 'pending',
                'point_of_interaction' => ['transaction_data' => ['qr_code' => 'pix', 'qr_code_base64' => 'abc']],
            ]);
        });

        // Com o OK de cookies o visitante é o cookie kazakora_visitor.
        $this->withCookies([Cookies::COOKIE_OK => Cookies::VERSAO, Cookies::COOKIE_VISITANTE => 'b6a1c1d2-0000-4000-8000-000000000001']);

        $this->call('POST', '/finalizacao/eventos', [], $this->prepareCookiesForRequest(), [], [
            'CONTENT_TYPE' => 'text/plain', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148 Safari/604.1',
        ], json_encode(['pagina' => 'abc123def456', 'eventos' => [['evento' => 'abriu']]]))->assertNoContent();

        $this->postJson('/finalizacao/entrega', $this->entrega(['guest' => ['name' => 'Maria Cliente', 'email' => 'maria2@exemplo.com', 'cpf' => '123.456.789-09', 'phone' => '(11) 99999-0000']]))->assertOk();
        $this->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true])->assertOk();

        $abriu = CheckoutEvento::where('evento', 'abriu')->sole();
        $pedido = CheckoutEvento::where('evento', CheckoutEvento::PEDIDO_CRIADO)->sole();
        $this->assertSame('b6a1c1d2-0000-4000-8000-000000000001', $abriu->visitor_id);
        $this->assertSame($abriu->visitor_id, $pedido->visitor_id);
        $this->assertSame(User::where('email', 'maria2@exemplo.com')->value('id'), $pedido->user_id);
    }

    public function test_pedido_grava_latitude_e_longitude_da_cloudflare(): void
    {
        Mail::fake();
        $this->noCarrinho(105);

        $this->mock(MercadoPagoPaymentService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createPixPayment')->andReturn([
                'id' => 78, 'status' => 'pending',
                'point_of_interaction' => ['transaction_data' => ['qr_code' => 'pix', 'qr_code_base64' => 'abc']],
            ]);
        });

        $this->postJson('/finalizacao/entrega', $this->entrega(['guest' => ['name' => 'Maria Cliente', 'email' => 'maria@exemplo.com', 'cpf' => '123.456.789-09', 'phone' => '(11) 99999-0000']]))->assertOk();

        $this->withHeaders(['CF-IPLatitude' => '-23.55052', 'CF-IPLongitude' => '-46.63331'])
            ->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true])
            ->assertOk();

        $order = Order::firstOrFail();
        $this->assertEquals(-23.55052, (float) $order->visitante_latitude);
        $this->assertEquals(-46.63331, (float) $order->visitante_longitude);
    }

    public function test_pedido_sem_cloudflare_fica_sem_localizacao(): void
    {
        Mail::fake();
        $this->noCarrinho(105);

        $this->mock(MercadoPagoPaymentService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createPixPayment')->andReturn([
                'id' => 79, 'status' => 'pending',
                'point_of_interaction' => ['transaction_data' => ['qr_code' => 'pix', 'qr_code_base64' => 'abc']],
            ]);
        });

        $this->postJson('/finalizacao/entrega', $this->entrega(['guest' => ['name' => 'Maria Cliente', 'email' => 'maria@exemplo.com', 'cpf' => '123.456.789-09', 'phone' => '(11) 99999-0000']]))->assertOk();

        $this->withHeaders(['CF-IPLatitude' => 'abc', 'CF-IPLongitude' => '999'])
            ->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true])
            ->assertOk();

        $order = Order::firstOrFail();
        $this->assertNull($order->visitante_latitude);
        $this->assertNull($order->visitante_longitude);
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
