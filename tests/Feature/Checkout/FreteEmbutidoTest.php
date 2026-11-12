<?php

namespace Tests\Feature\Checkout;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Company;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Frete grátis com o frete embutido no preço (pedido 2026-10-12): o preço da
 * loja tem o frete da região do cliente; sem região, R$ 30 (PAC) / R$ 60
 * (SEDEX). Fora da área FULL o checkout oferece o TURBO (SEDEX) pela diferença.
 */
class FreteEmbutidoTest extends TestCase
{
    use RefreshDatabase;

    private const CEP_CE = '60025-000';

    private ShippingMethod $frete;

    protected function setUp(): void
    {
        parent::setUp();
        // Os testes abaixo conferem a conta do frete pelo CEP atual; o preço
        // fixo na navegação tem testes próprios no fim.
        config(['frete_embutido.ativo' => true, 'frete_embutido.fixo_na_navegacao' => false]);
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        $this->frete = ShippingMethod::factory()->create(['price' => 0, 'is_active' => true]);
    }

    private function comCorreios(): void
    {
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

        // Ceará: PAC R$ 20 em 8 dias, SEDEX R$ 30 em 3. São Paulo: SEDEX mais barato que o PAC.
        Http::fake(function (Request $request) {
            // Só os Correios: o ViaCEP (rua do CEP) tem fake próprio nos testes que precisam.
            if (! str_contains($request->url(), 'correios.test')) {
                return null;
            }
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

    private function produto(float $preco = 100): Product
    {
        return Product::factory()->create(['price' => $preco, 'stock' => 5, 'is_active' => true]);
    }

    private function entrega(string $cep, array $extra = []): array
    {
        return array_merge([
            'shipping_method_id' => $this->frete->id,
            'guest' => ['name' => 'Maria Cliente', 'email' => 'maria@exemplo.com', 'cpf' => '123.456.789-09', 'phone' => '(85) 99999-0000'],
            'new_address' => [
                'recipient_name' => 'Maria Cliente', 'phone' => '(85) 99999-0000', 'zip' => $cep,
                'street' => 'Rua A', 'number' => '20', 'neighborhood' => 'Centro', 'city' => 'Fortaleza', 'state' => 'CE',
            ],
        ], $extra);
    }

    public function test_sem_regiao_soma_30_reais_na_loja_e_nada_fora_dela(): void
    {
        $produto = $this->produto(100);

        $this->get("/produtos/{$produto->slug}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('product.final_price', 130));

        // Fora de uma tela da loja (admin, marketplaces, filas): preço de cadastro.
        // (O serviço é "scoped": cada requisição/job começa com ele desligado.)
        app()->forgetScopedInstances();
        $this->assertSame(100.0, Product::find($produto->id)->final_price);
    }

    public function test_cep_digitado_no_produto_poe_o_frete_exato_no_preco(): void
    {
        $this->comCorreios();
        $produto = $this->produto(100);

        $this->getJson('/frete/prazo?cep=60025000&produto='.$produto->id)
            ->assertOk()
            ->assertJsonPath('final_price', 120)
            ->assertCookie('kazakora_cep');

        $this->get("/produtos/{$produto->slug}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('product.final_price', 120));
    }

    public function test_teto_do_frete_somado_so_ate_sobrar_a_margem_minima(): void
    {
        // Pedido do Lira em 2026-11-10: frete alto em lugar longe, em todos os
        // produtos. Ceará: PAC R$ 20; teto de R$ 15 só neste teste.
        $this->comCorreios();
        config(['frete_embutido.teto_embutido' => 15]);
        // Margem folgada (custo R$ 10, grande inclusive): a loja paga os R$ 5 acima do teto.
        $folgado = Product::factory()->create(['price' => 100, 'cost_price' => 10, 'stock' => 5, 'is_active' => true, 'categoria_frete' => 'grande']);
        // Margem apertada (custo R$ 75): pagar R$ 5 deixaria menos de 10% de margem.
        $apertado = Product::factory()->create(['price' => 100, 'cost_price' => 75, 'stock' => 5, 'is_active' => true]);
        // Sem custo cadastrado: não dá para conferir a margem, fica sem teto.
        $semCusto = Product::factory()->create(['price' => 100, 'cost_price' => null, 'stock' => 5, 'is_active' => true]);

        $this->getJson('/frete/prazo?cep=60025000&produto='.$folgado->id)->assertOk()->assertJsonPath('final_price', 115);
        $this->get("/produtos/{$folgado->slug}")->assertInertia(fn (AssertableInertia $page) => $page->where('product.final_price', 115));
        $this->get("/produtos/{$apertado->slug}")->assertInertia(fn (AssertableInertia $page) => $page->where('product.final_price', 120));
        $this->get("/produtos/{$semCusto->slug}")->assertInertia(fn (AssertableInertia $page) => $page->where('product.final_price', 120));

        // O custo real da caixa continua o do destino: a loja paga a diferença.
        $frete = app(\App\Modules\Checkout\Support\FreteEmbutido::class)->paraProduto(Product::find($folgado->id));
        $this->assertSame(15.0, $frete['embutido']);
        $this->assertEquals(20.0, $frete['correios']);
    }

    public function test_piso_sobe_o_preco_so_onde_a_venda_daria_prejuizo(): void
    {
        // Pedido do Lira em 2026-11-10: "aumenta a margem nessas regiões". Ceará: PAC R$ 20.
        $this->comCorreios();
        // R$ 30 + R$ 20 de frete = R$ 50; custo 25 + frete 20 + insumos 2 = 47; com imposto
        // e taxa (9,5%) sobram R$ 45,25: prejuízo. Sobe até 10% de margem: 47 / 0,805 = 58,39.
        $prejuizo = Product::factory()->create(['price' => 30, 'cost_price' => 25, 'stock' => 5, 'is_active' => true]);
        // Lucro baixo mas positivo (R$ 1,60): não mexe.
        $apertado = Product::factory()->create(['price' => 100, 'cost_price' => 85, 'stock' => 5, 'is_active' => true]);

        $this->getJson('/frete/prazo?cep=60025000&produto='.$prejuizo->id)->assertOk()->assertJsonPath('final_price', 58.39);
        $this->get("/produtos/{$prejuizo->slug}")->assertInertia(fn (AssertableInertia $page) => $page->where('product.final_price', 58.39));
        $this->get("/produtos/{$apertado->slug}")->assertInertia(fn (AssertableInertia $page) => $page->where('product.final_price', 120));

        $frete = app(\App\Modules\Checkout\Support\FreteEmbutido::class)->paraProduto(Product::find($prejuizo->id));
        $this->assertTrue($frete['piso']);
        $this->assertEquals(20.0, $frete['correios']);

        // Desligado pela configuração: volta ao frete puro.
        config(['frete_embutido.piso_margem' => false]);
        app()->forgetScopedInstances();
        $this->get("/produtos/{$prejuizo->slug}")->assertInertia(fn (AssertableInertia $page) => $page->where('product.final_price', 50));
    }

    public function test_cupom_do_produto_entra_na_conta_do_piso_so_onde_daria_prejuizo(): void
    {
        // Pedido do Lira em 2026-11-12 (BLACKFRIDAY30 do massageador): com 30% de cupom o
        // Norte dava prejuízo. Ceará: PAC R$ 20. R$ 100 + R$ 20 = R$ 120; custo 70 + frete 20
        // + insumos 2 = 92. Com o cupom, 120 x 0,7 x 0,905 = 76,02: prejuízo. Sobe até não
        // perder: 92 / (0,7 x 0,905) = 145,23.
        $this->comCorreios();
        $comCupom = Product::factory()->create(['price' => 100, 'cost_price' => 70, 'stock' => 5, 'is_active' => true]);
        $semCupom = Product::factory()->create(['price' => 100, 'cost_price' => 70, 'stock' => 5, 'is_active' => true]);
        // Cupom que dá lucro mesmo com o desconto: preço não muda.
        $folgado = Product::factory()->create(['price' => 100, 'cost_price' => 40, 'stock' => 5, 'is_active' => true]);

        $cupom = \App\Modules\Checkout\Models\Coupon::query()->create([
            'code' => 'BLACKFRIDAY30', 'name' => 'Black', 'discount_type' => 'percentage', 'discount_value' => 30,
            'is_active' => true, 'starts_at' => now()->subDay(), 'expires_at' => now()->addDay(),
            'produtos' => [$comCupom->id, $folgado->id],
        ]);

        $this->getJson('/frete/prazo?cep=60025000&produto='.$comCupom->id)->assertOk()->assertJsonPath('final_price', 145.23);
        $this->getJson('/frete/prazo?cep=60025000&produto='.$semCupom->id)->assertOk()->assertJsonPath('final_price', 120);
        $this->getJson('/frete/prazo?cep=60025000&produto='.$folgado->id)->assertOk()->assertJsonPath('final_price', 120);

        // Cupom vencido: volta à conta normal (sem prejuízo sem o cupom).
        $cupom->update(['expires_at' => now()->subMinute()]);
        app()->forgetScopedInstances();
        $this->getJson('/frete/prazo?cep=60025000&produto='.$comCupom->id)->assertOk()->assertJsonPath('final_price', 120);
    }

    public function test_cep_no_produto_mostra_rua_e_turbo_fora_do_full(): void
    {
        Http::fake(['viacep.com.br/*' => Http::response(['logradouro' => 'Rua Pedro Pereira', 'bairro' => 'Centro', 'localidade' => 'Fortaleza', 'uf' => 'CE'])]);
        $this->comCorreios();
        $produto = $this->produto(100);

        // Pedido 2026-10-23: rua depois do CEP e o mesmo TURBO do checkout (SEDEX R$ 30 - PAC R$ 20).
        $this->getJson('/frete/prazo?cep=60025000&produto='.$produto->id)
            ->assertOk()
            ->assertJsonPath('endereco.rua', 'Rua Pedro Pereira')
            ->assertJsonPath('endereco.cidade', 'Fortaleza')
            ->assertJsonPath('endereco.uf', 'CE')
            ->assertJsonPath('entrega_expressa', null)
            ->assertJsonPath('turbo.preco', 10)
            ->assertJsonPath('turbo.dias_a_menos', 5);
    }

    public function test_cep_no_produto_tem_o_mesmo_prazo_e_turbo_do_checkout_na_quantidade_escolhida(): void
    {
        Http::fake(['viacep.com.br/*' => Http::response([])]);
        $this->comCorreios();
        $produto = $this->produto(100);
        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 2]);
        $checkout = $this->getJson('/finalizacao/entrega-gratis?cep=60025000')->json();

        $this->getJson('/frete/prazo?cep=60025000&produto='.$produto->id.'&quantidade=2')
            ->assertOk()
            ->assertJsonPath('prazo_gratis', $checkout['prazo'])
            ->assertJsonPath('turbo.preco', $checkout['turbo']['preco'])
            ->assertJsonPath('turbo.prazo', $checkout['turbo']['prazo'])
            ->assertJsonPath('turbo.dias_a_menos', $checkout['turbo']['dias_a_menos']);
        $this->assertSame(8, $checkout['prazo']);
    }

    public function test_cep_no_produto_na_area_full_nao_tem_turbo(): void
    {
        Http::fake(['viacep.com.br/*' => Http::response(['logradouro' => 'Praça da Sé', 'bairro' => 'Sé', 'localidade' => 'São Paulo', 'uf' => 'SP'])]);
        $this->comCorreios();
        $produto = $this->produto(100);

        $this->getJson('/frete/prazo?cep=01001000&produto='.$produto->id)
            ->assertOk()
            ->assertJsonPath('endereco.rua', 'Praça da Sé')
            ->assertJsonPath('turbo', null)
            ->assertJsonPath('entrega_expressa.local', 'São Paulo');
    }

    public function test_checkout_fora_de_sp_tem_frete_gratis_turbo_e_desconto_da_caixa_unica(): void
    {
        $this->comCorreios();
        $produto = $this->produto(100);
        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 2]);

        $this->getJson('/finalizacao/entrega-gratis?cep=60025000')
            ->assertOk()
            ->assertJsonPath('subtotal', 240)
            ->assertJsonPath('prazo', 8)
            ->assertJsonPath('entrega_expressa', null)
            // Caixa única: PAC R$ 20 (não 2 x 20), SEDEX R$ 30 → TURBO pela diferença da caixa.
            ->assertJsonPath('turbo.preco', 10)
            ->assertJsonPath('turbo.prazo', 3)
            ->assertJsonPath('turbo.dias_a_menos', 5)
            ->assertJsonPath('desconto_plataforma', 20);
    }

    public function test_turbo_acima_do_teto_nao_aparece(): void
    {
        $this->comCorreios();
        config(['frete_embutido.turbo_max' => 9.99]);
        $produto = $this->produto(100);
        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 1]);

        // TURBO do Ceará custa R$ 10: passa do teto.
        $this->getJson('/finalizacao/entrega-gratis?cep=60025000')
            ->assertOk()
            ->assertJsonPath('turbo', null);
    }

    public function test_sem_resposta_dos_correios_o_turbo_aparece_pelo_valor_de_seguranca_ate_60(): void
    {
        $produto = $this->produto(100);
        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 2]);

        // R$ 60 (SEDEX) − R$ 30 (PAC) por unidade, sem prazo.
        $this->getJson('/finalizacao/entrega-gratis?cep=60025000')
            ->assertOk()
            ->assertJsonPath('turbo.preco', 60)
            ->assertJsonPath('turbo.prazo', null);

        // 3 unidades: R$ 90, passa do teto de R$ 60.
        $this->patch("/carrinho/{$produto->id}", ['quantity' => 3]);
        $this->getJson('/finalizacao/entrega-gratis?cep=60025000')
            ->assertOk()
            ->assertJsonPath('turbo', null);
    }

    public function test_na_area_full_nao_tem_turbo_e_embute_o_sedex_quando_e_mais_barato(): void
    {
        $this->comCorreios();
        $produto = $this->produto(100);
        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 1]);

        $this->getJson('/finalizacao/entrega-gratis?cep=03187040')
            ->assertOk()
            ->assertJsonPath('subtotal', 109.94)
            ->assertJsonPath('turbo', null);
    }

    public function test_pedido_sai_com_o_frete_no_preco_do_item_e_o_turbo_na_entrega(): void
    {
        Mail::fake();
        $this->comCorreios();
        $produto = $this->produto(100);
        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 1]);

        $this->mock(MercadoPagoPaymentService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createPixPayment')->once()->andReturn([
                'id' => 77, 'status' => 'pending',
                'point_of_interaction' => ['transaction_data' => ['qr_code' => 'pix', 'qr_code_base64' => 'abc']],
            ]);
        });

        $this->postJson('/finalizacao/entrega', $this->entrega(self::CEP_CE, ['turbo' => true]))->assertOk();
        $this->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true])->assertOk();

        $order = Order::with('items')->firstOrFail();
        $this->assertEquals(120.0, (float) $order->items->first()->product_price);
        $this->assertEquals(10.0, (float) $order->shipping_cost);
        $this->assertSame('SEDEX (TURBO)', $order->shipping_carrier_name);
        // Quanto do preço era frete fica guardado no item e no pedido.
        $this->assertEquals(20.0, (float) $order->items->first()->frete_embutido);
        $this->assertEquals(20.0, (float) $order->frete_embutido_amount);
    }

    public function test_estado_pelo_ip_da_cloudflare_vale_na_vitrine_carrinho_e_checkout(): void
    {
        $this->comCorreios();
        config(['app.url' => 'https://kazakora.com']);
        $produto = $this->produto(100);
        $ceara = ['CF-IPCountry' => 'BR', 'CF-Region-Code' => 'CE'];

        $this->get("/produtos/{$produto->slug}", $ceara)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('product.final_price', 120));

        // No kazakora.com o ?simular_uf= não vale: só o cabeçalho da Cloudflare.
        // (No teste o serviço "scoped" não zera sozinho entre as requisições.)
        app()->forgetScopedInstances();
        $this->get("/produtos/{$produto->slug}?simular_uf=CE")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('product.final_price', 130));

        // Fora do Brasil, sem região: valor de segurança.
        app()->forgetScopedInstances();
        $this->get("/produtos/{$produto->slug}", ['CF-IPCountry' => 'US', 'CF-Region-Code' => 'CE'])
            ->assertInertia(fn (AssertableInertia $page) => $page->where('product.final_price', 130));

        app()->forgetScopedInstances();
        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 1], $ceara);
        $this->get('/carrinho', $ceara)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('total', 120));

        // CEP do mesmo estado no checkout: o mesmo preço da vitrine.
        $this->getJson('/finalizacao/entrega-gratis?cep=60025000', $ceara)
            ->assertOk()
            ->assertJsonPath('subtotal', 120);
    }

    public function test_turbo_nao_cobra_o_valor_que_vem_do_navegador(): void
    {
        Mail::fake();
        $this->comCorreios();
        $produto = $this->produto(100);
        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 1]);

        $this->mock(MercadoPagoPaymentService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createPixPayment')->once()->andReturn([
                'id' => 78, 'status' => 'pending',
                'point_of_interaction' => ['transaction_data' => ['qr_code' => 'pix', 'qr_code_base64' => 'abc']],
            ]);
        });

        // SP (área FULL): TURBO pedido pelo navegador é ignorado, entrega grátis.
        $this->postJson('/finalizacao/entrega', $this->entrega('03187-040', ['turbo' => true]))->assertOk();
        $this->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true])->assertOk();

        $this->assertEquals(0.0, (float) Order::firstOrFail()->shipping_cost);
    }

    public function test_pedido_com_varios_itens_desconta_a_caixa_unica(): void
    {
        Mail::fake();
        $this->comCorreios();
        $produto = $this->produto(100);
        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 3]);

        $this->mock(MercadoPagoPaymentService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createPixPayment')->once()->andReturn([
                'id' => 79, 'status' => 'pending',
                'point_of_interaction' => ['transaction_data' => ['qr_code' => 'pix', 'qr_code_base64' => 'abc']],
            ]);
        });

        $this->postJson('/finalizacao/entrega', $this->entrega(self::CEP_CE))->assertOk();
        $this->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true])->assertOk();

        // 3 x (100 + 20) = 360; caixa única custa 20, então 40 de desconto da plataforma.
        $order = Order::firstOrFail();
        $this->assertEquals(360.0, (float) $order->subtotal);
        $this->assertEquals(40.0, (float) $order->desconto_plataforma_amount);
        $pix = round((360 - 40) * 5 / 100, 2);
        $this->assertEquals(round(320 - $pix, 2), (float) $order->total);
        $this->assertEquals(round(40 + $pix, 2), (float) $order->discount_amount);
    }

    public function test_produto_sem_frete_mostra_o_preco_do_cadastro_no_produto_carrinho_e_pedido(): void
    {
        Mail::fake();
        $this->comCorreios();
        $produto = $this->produto(100);
        config(['frete_embutido.produtos_sem_frete' => [$produto->id]]);

        $this->get("/produtos/{$produto->slug}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('product.final_price', 100));
        $this->getJson('/frete/prazo?cep=60025000&produto='.$produto->id)->assertJsonPath('final_price', 100);

        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 1]);
        $this->getJson('/finalizacao/entrega-gratis?cep=60025000')
            ->assertOk()
            ->assertJsonPath('subtotal', 100)
            ->assertJsonPath('desconto_plataforma', 0);

        $this->mock(MercadoPagoPaymentService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createPixPayment')->once()->andReturn([
                'id' => 78, 'status' => 'pending',
                'point_of_interaction' => ['transaction_data' => ['qr_code' => 'pix', 'qr_code_base64' => 'abc']],
            ]);
        });
        $this->postJson('/finalizacao/entrega', $this->entrega(self::CEP_CE))->assertOk();
        $this->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true])->assertOk();

        $order = Order::with('items')->firstOrFail();
        $this->assertEquals(100.0, (float) $order->items->first()->product_price);
        $this->assertEquals(0.0, (float) $order->shipping_cost);
        $this->assertEquals(0.0, (float) $order->items->first()->frete_embutido);
    }
    public function test_preco_fica_fixo_da_vitrine_ao_pagamento_mesmo_trocando_o_cep(): void
    {
        Mail::fake();
        $this->comCorreios();
        config(['app.url' => 'https://kazakora.com', 'frete_embutido.fixo_na_navegacao' => true]);
        $produto = $this->produto(100);
        $saoPaulo = ['CF-IPCountry' => 'BR', 'CF-Region-Code' => 'SP'];

        // Pedido 2026-10-25: pelo IP de SP o cliente vê 100 + 9,94 (SEDEX de SP).
        $this->get("/produtos/{$produto->slug}", $saoPaulo)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('product.final_price', 109.94));

        // CEP do Ceará digitado no produto: o preço não muda.
        app()->forgetScopedInstances();
        $this->getJson('/frete/prazo?cep=60025000&produto='.$produto->id, $saoPaulo)
            ->assertOk()
            ->assertJsonPath('final_price', 109.94);

        app()->forgetScopedInstances();
        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 1], $saoPaulo);
        $this->get('/carrinho', $saoPaulo)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('total', 109.94));

        app()->forgetScopedInstances();
        $this->getJson('/finalizacao/entrega-gratis?cep=60025000', $saoPaulo)
            ->assertOk()
            ->assertJsonPath('subtotal', 109.94);

        $this->mock(MercadoPagoPaymentService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createPixPayment')->once()->andReturn([
                'id' => 79, 'status' => 'pending',
                'point_of_interaction' => ['transaction_data' => ['qr_code' => 'pix', 'qr_code_base64' => 'abc']],
            ]);
        });

        app()->forgetScopedInstances();
        $this->postJson('/finalizacao/entrega', $this->entrega(self::CEP_CE), $saoPaulo)->assertOk();
        app()->forgetScopedInstances();
        $this->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true], $saoPaulo)->assertOk();

        $order = Order::with('items')->firstOrFail();
        $this->assertEquals(109.94, (float) $order->items->first()->product_price);
    }

    public function test_preco_fixo_vale_por_visitante_e_nao_para_o_admin(): void
    {
        $this->comCorreios();
        config(['frete_embutido.fixo_na_navegacao' => true]);
        $produto = $this->produto(100);

        $this->getJson('/frete/prazo?cep=60025000&produto='.$produto->id)->assertJsonPath('final_price', 120);

        // Outro visitante (sessão nova) em SP vê o frete dele.
        $this->flushSession();
        app()->forgetScopedInstances();
        $this->getJson('/frete/prazo?cep=03187040&produto='.$produto->id)->assertJsonPath('final_price', 109.94);

        app()->forgetScopedInstances();
        $this->assertSame(100.0, Product::find($produto->id)->final_price);
    }
}
