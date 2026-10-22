<?php

namespace Tests\Feature\Checkout;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Support\FreteEmbutido;
use App\Modules\Fiscal\Models\Company;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Frete da KazaKora (pedido do Lira em 2026-10-21): preço sem frete somado
 * pela região; grátis só com pedido a partir de R$ 200 e entrega no Sul ou
 * Sudeste. Fora disso o cliente paga o frete da caixa pelos Correios.
 */
class FreteGratisSulSudesteTest extends TestCase
{
    use RefreshDatabase;

    private const CEP_CE = '60025-000';

    private const CEP_SP = '01310-100';

    protected function setUp(): void
    {
        parent::setUp();
        config(['frete_embutido.ativo' => true]);
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);

        Company::create([
            'razao_social' => 'Kazakora Comércio Ltda', 'nome_fantasia' => 'KazaKora', 'cnpj' => '65604590000107',
            'regime_tributario' => Company::REGIME_SIMPLES_NACIONAL, 'phone' => '(11) 91234-5678', 'email' => 'contato@kazakora.com',
            'zip' => '03187-040', 'street' => 'Rua da Loja', 'number' => '100', 'neighborhood' => 'Mooca', 'city' => 'São Paulo', 'state' => 'SP',
        ]);
        config([
            'services.correios.numero_usuario' => '123',
            'services.correios.codigo_acesso_contrato' => 'token-contrato',
            'services.correios.preco_base_url' => 'https://correios.test/preco',
            'services.correios.prazo_base_url' => 'https://correios.test/prazo',
        ]);

        // Ceará: PAC R$ 20 em 8 dias, SEDEX R$ 30 em 3. São Paulo: SEDEX R$ 9,94, mais barato que o PAC.
        Http::fake(function (Request $request) {
            $sp = fn ($p) => str_starts_with($p['cepDestino'], '0');
            if (str_contains($request->url(), '/preco/')) {
                return Http::response(collect($request['parametrosProduto'])->map(fn ($p) => [
                    'coProduto' => $p['coProduto'], 'nuRequisicao' => $p['nuRequisicao'],
                    'pcFinal' => $p['coProduto'] === '03220' ? ($sp($p) ? '9,94' : '30,00') : ($sp($p) ? '15,45' : '20,00'),
                ])->all());
            }

            return Http::response(collect($request['parametrosPrazo'])->map(fn ($p) => [
                'coProduto' => $p['coProduto'], 'nuRequisicao' => $p['nuRequisicao'],
                'prazoEntrega' => $p['coProduto'] === '03220' ? 3 : 8,
            ])->all());
        });
    }

    private function noCarrinho(float $preco, int $quantidade): Product
    {
        $produto = Product::factory()->create(['price' => $preco, 'stock' => 5, 'is_active' => true]);
        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => $quantidade]);

        return $produto;
    }

    private function pagarNoPix(string $cep, array $extra = []): Order
    {
        Mail::fake();
        $this->mock(MercadoPagoPaymentService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createPixPayment')->once()->andReturn([
                'id' => 90, 'status' => 'pending',
                'point_of_interaction' => ['transaction_data' => ['qr_code' => 'pix', 'qr_code_base64' => 'abc']],
            ]);
        });

        $this->postJson('/finalizacao/entrega', array_merge([
            'guest' => ['name' => 'Maria Cliente', 'email' => 'maria@exemplo.com', 'cpf' => '123.456.789-09', 'phone' => '(85) 99999-0000'],
            'new_address' => [
                'recipient_name' => 'Maria Cliente', 'phone' => '(85) 99999-0000', 'zip' => $cep,
                'street' => 'Rua A', 'number' => '20', 'neighborhood' => 'Centro', 'city' => 'Cidade', 'state' => 'SP',
            ],
        ], $extra))->assertOk();
        $this->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true])->assertOk();

        return Order::firstOrFail();
    }

    public function test_preco_da_loja_e_o_de_cadastro_sem_frete_pelo_ip(): void
    {
        $produto = Product::factory()->create(['price' => 100, 'stock' => 5, 'is_active' => true]);

        $this->withHeaders(['CF-IPCountry' => 'BR', 'CF-Region-Code' => 'CE'])
            ->get("/produtos/{$produto->slug}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('product.final_price', 100));
    }

    public function test_uf_pelo_cep(): void
    {
        $this->assertSame('SP', FreteEmbutido::ufDoCep('01310-100'));
        $this->assertSame('RJ', FreteEmbutido::ufDoCep('20040020'));
        $this->assertSame('MG', FreteEmbutido::ufDoCep('30130-000'));
        $this->assertSame('ES', FreteEmbutido::ufDoCep('29010-000'));
        $this->assertSame('PR', FreteEmbutido::ufDoCep('80010-000'));
        $this->assertSame('SC', FreteEmbutido::ufDoCep('88010-000'));
        $this->assertSame('RS', FreteEmbutido::ufDoCep('90010-000'));
        $this->assertSame('CE', FreteEmbutido::ufDoCep('60025-000'));
        $this->assertSame('DF', FreteEmbutido::ufDoCep('70040-000'));
        $this->assertNull(FreteEmbutido::ufDoCep('123'));
    }

    public function test_sudeste_abaixo_de_200_paga_o_frete_e_mostra_quanto_falta(): void
    {
        $this->noCarrinho(100, 1);

        $this->getJson('/finalizacao/entrega-gratis?cep=01310100')
            ->assertOk()
            ->assertJsonPath('subtotal', 100)
            ->assertJsonPath('desconto_plataforma', 0)
            ->assertJsonPath('frete.gratis', false)
            ->assertJsonPath('frete.preco', 9.94)
            ->assertJsonPath('frete.regiao_gratis', true)
            ->assertJsonPath('frete.falta', 100);
    }

    public function test_sudeste_a_partir_de_200_e_gratis(): void
    {
        $this->noCarrinho(100, 2);

        $this->getJson('/finalizacao/entrega-gratis?cep=01310100')
            ->assertOk()
            ->assertJsonPath('subtotal', 200)
            ->assertJsonPath('frete.gratis', true)
            ->assertJsonPath('frete.preco', 0);
    }

    public function test_nordeste_paga_frete_mesmo_acima_de_200(): void
    {
        $this->noCarrinho(150, 2);

        $this->getJson('/finalizacao/entrega-gratis?cep=60025000')
            ->assertOk()
            ->assertJsonPath('subtotal', 300)
            ->assertJsonPath('frete.gratis', false)
            ->assertJsonPath('frete.regiao_gratis', false)
            // Caixa única: PAC R$ 20 (não 2 x 20).
            ->assertJsonPath('frete.preco', 20)
            ->assertJsonPath('turbo.preco', 10);
    }

    public function test_pedido_do_sudeste_acima_de_200_sai_sem_frete(): void
    {
        $this->noCarrinho(100, 2);

        $order = $this->pagarNoPix(self::CEP_SP);

        $this->assertEquals(200.0, (float) $order->subtotal);
        $this->assertEquals(0.0, (float) $order->shipping_cost);
        $this->assertEquals(0.0, (float) $order->desconto_plataforma_amount);
        $this->assertSame('Frete Grátis', $order->shipping_carrier_name);
    }

    public function test_pedido_fora_do_sul_e_sudeste_cobra_o_frete_sem_desconto_pix_nele(): void
    {
        $this->noCarrinho(150, 2);

        $order = $this->pagarNoPix(self::CEP_CE);

        $this->assertEquals(300.0, (float) $order->subtotal);
        $this->assertEquals(20.0, (float) $order->shipping_cost);
        $this->assertSame(FreteEmbutido::FRETE_PAGO, $order->shipping_carrier_name);
        $pix = round(300 * 5 / 100, 2);
        $this->assertEquals(round(300 - $pix + 20, 2), (float) $order->total);
    }

    public function test_turbo_com_frete_pago_cobra_o_sedex_inteiro(): void
    {
        $this->noCarrinho(100, 1);

        $order = $this->pagarNoPix(self::CEP_CE, ['turbo' => true]);

        // PAC R$ 20 + diferença do SEDEX R$ 10.
        $this->assertEquals(30.0, (float) $order->shipping_cost);
        $this->assertSame(FreteEmbutido::TURBO, $order->shipping_carrier_name);
    }

    public function test_cupom_que_derruba_o_pedido_abaixo_de_200_volta_a_cobrar_o_frete(): void
    {
        $this->noCarrinho(100, 2);
        \App\Modules\Checkout\Models\Coupon::create([
            'code' => 'DEZ', 'discount_type' => 'fixed', 'discount_value' => 10, 'is_active' => true,
        ]);
        $this->postJson('/finalizacao/cupom', ['code' => 'DEZ'])->assertOk();

        $this->getJson('/finalizacao/entrega-gratis?cep=01310100')
            ->assertOk()
            ->assertJsonPath('discount_amount', 10)
            ->assertJsonPath('frete.gratis', false)
            ->assertJsonPath('frete.falta', 10);
    }
}
