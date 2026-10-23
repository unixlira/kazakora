<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Support\PaymentGateway;
use App\Support\Privacidade\Cookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Checkout no subdomínio seguro (pedido 2026-10-23): o botão de compra em
 * kazakora.com leva para https://seguro.kazakora.com/pagamento com o
 * carrinho, o login e o OK de cookies, por uma passagem de uso único.
 */
class CheckoutSeguroTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        ShippingMethod::factory()->create(['price' => 0, 'is_active' => true]);
        config(['loja.checkout_seguro_hosts' => ['kazakora.com']]);
    }

    /** Simula outro endereço: o navegador não leva a sessão de um host para o outro. */
    private function trocarDeEndereco(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    private function passagemDe(string $location): string
    {
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query['passagem'];
    }

    public function test_finalizar_na_loja_leva_ao_seguro_com_o_carrinho(): void
    {
        $product = Product::factory()->create(['price' => 120, 'stock' => 5, 'is_active' => true]);
        $this->post('https://kazakora.com/carrinho', ['product_id' => $product->id, 'quantity' => 2]);

        $destino = $this->get('https://kazakora.com/finalizacao')->headers->get('Location');
        $this->assertStringStartsWith('https://seguro.kazakora.com/pagamento?passagem=', $destino);

        $this->trocarDeEndereco();

        $this->get($destino)->assertRedirect('/pagamento');
        $this->get('https://seguro.kazakora.com/pagamento')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Checkout/CheckoutV2', false)
                ->where('items.0.product.id', $product->id)
                ->where('items.0.quantity', 2));
    }

    public function test_comprar_agora_pelo_inertia_sai_da_loja_com_location(): void
    {
        $product = Product::factory()->create(['price' => 80, 'stock' => 5, 'is_active' => true]);
        $this->post('https://kazakora.com/comprar-agora', ['product_id' => $product->id, 'quantity' => 1])
            ->assertRedirect('https://kazakora.com/finalizacao');

        $resposta = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request())])->get('https://kazakora.com/finalizacao');

        $resposta->assertStatus(409);
        $this->assertStringStartsWith('https://seguro.kazakora.com/pagamento?passagem=', $resposta->headers->get('X-Inertia-Location'));
    }

    public function test_passagem_leva_o_login_e_vale_uma_vez_so(): void
    {
        $cliente = User::factory()->create();
        $product = Product::factory()->create(['price' => 50, 'stock' => 5, 'is_active' => true]);
        $this->actingAs($cliente)->post('https://kazakora.com/carrinho', ['product_id' => $product->id, 'quantity' => 1]);

        $destino = $this->get('https://kazakora.com/finalizacao')->headers->get('Location');

        $this->trocarDeEndereco();
        $this->get($destino);
        $this->assertAuthenticatedAs($cliente);

        $this->trocarDeEndereco();
        $this->get($destino)->assertRedirect('/pagamento');
        $this->assertGuest();
    }

    public function test_passagem_leva_o_ok_de_cookies(): void
    {
        $product = Product::factory()->create(['price' => 50, 'stock' => 5, 'is_active' => true]);
        $this->post('https://kazakora.com/carrinho', ['product_id' => $product->id, 'quantity' => 1]);

        $destino = $this->withCookie(Cookies::COOKIE_OK, Cookies::VERSAO)
            ->get('https://kazakora.com/finalizacao')->headers->get('Location');

        $this->trocarDeEndereco();

        $this->get($destino)->assertCookie(Cookies::COOKIE_OK, Cookies::VERSAO);
    }

    public function test_no_seguro_finalizacao_vira_pagamento(): void
    {
        $this->get('https://seguro.kazakora.com/finalizacao?v=1')->assertRedirect('/pagamento?v=1');
    }

    public function test_pagina_da_loja_no_seguro_volta_para_a_loja_sem_o_carrinho_da_compra(): void
    {
        $product = Product::factory()->create(['price' => 50, 'stock' => 5, 'is_active' => true]);
        $this->post('https://kazakora.com/carrinho', ['product_id' => $product->id, 'quantity' => 1]);
        $ida = $this->get('https://kazakora.com/finalizacao')->headers->get('Location');

        $this->trocarDeEndereco();
        $this->get($ida);
        $this->session(['cart' => []]); // compra concluída no seguro

        $volta = $this->get('https://seguro.kazakora.com/produtos?ordenar=menor')->headers->get('Location');
        $this->assertStringStartsWith('https://kazakora.com/produtos?ordenar=menor&passagem=', $volta);

        // De volta na loja (sessão antiga ainda com o item): o carrinho fica vazio.
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->session(['cart' => [$product->id => 1]]);

        $this->get($volta)->assertRedirect('/produtos?ordenar=menor');
        $this->assertSame([], session('cart'));
    }

    public function test_desligado_a_finalizacao_fica_na_loja(): void
    {
        config(['loja.checkout_seguro_hosts' => []]);
        $product = Product::factory()->create(['price' => 50, 'stock' => 5, 'is_active' => true]);
        $this->post('https://kazakora.com/carrinho', ['product_id' => $product->id, 'quantity' => 1]);

        $this->get('https://kazakora.com/finalizacao')->assertOk();
        $this->get('https://kazakora.com/pagamento')->assertRedirect(route('finalizacao.entrega'));
        $this->get('https://seguro.kazakora.com/carrinho')->assertOk();
    }

    public function test_passagem_invalida_nao_quebra(): void
    {
        $this->get('https://seguro.kazakora.com/pagamento?passagem=inventada')->assertRedirect('/pagamento');
        $this->assertGuest();
    }

    public function test_chamadas_do_checkout_no_seguro_nao_sao_redirecionadas(): void
    {
        $this->getJson('https://seguro.kazakora.com/finalizacao/entrega-gratis?cep=03187040')
            ->assertHeaderMissing('Location');
    }
}
