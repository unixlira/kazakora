<?php

namespace Tests\Feature\Checkout;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Support\FreteEmbutido;
use App\Modules\Fiscal\Models\Company;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Diferença de frete do CEP (pedido do Lira 2026-11-24): o preço que o cliente
 * viu navegando (frete da região dele embutido) nunca muda no checkout. Com o
 * CEP de entrega, o servidor calcula o frete que esse CEP pede: se cabe no
 * embutido, frete grátis sem aviso; se passa, a diferença vira a linha "Frete"
 * do resumo e entra no total e no pedido. A Entrega Expressa cobra só o que
 * passa do embutido.
 *
 * Correios de mentira: São Paulo SEDEX R$ 9,94 (mais barato que o PAC de
 * R$ 15,45); Ceará PAC R$ 20 em 8 dias e SEDEX R$ 30 em 3.
 */
class FreteDiferencaDoCepTest extends TestCase
{
    use RefreshDatabase;

    private const CEP_SP = '01310-100';

    private const CEP_CE = '60025-000';

    private const SAO_PAULO = ['CF-IPCountry' => 'BR', 'CF-Region-Code' => 'SP'];

    private ShippingMethod $frete;

    protected function setUp(): void
    {
        parent::setUp();
        // Terça de manhã; motoboy desligado para a Expressa ser o SEDEX.
        Carbon::setTestNow(Carbon::parse('2026-11-24 10:00', 'America/Sao_Paulo'));
        config([
            'app.url' => 'https://kazakora.com',
            'frete_embutido.ativo' => true,
            'frete_embutido.fixo_na_navegacao' => true,
            'frete_embutido.transportadoras_melhor_envio' => [],
            'entrega_expressa.motoboy.valor_sp' => 0.0,
        ]);
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        $this->frete = ShippingMethod::factory()->create(['price' => 0, 'is_active' => true]);
        $this->comCorreios();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
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

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'viacep.com.br')) {
                return Http::response([]);
            }
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

    /** Cliente navegando de SP (IP) vê o produto e põe no carrinho: R$ 100 + R$ 9,94 de frete embutido. */
    private function navegaDeSaoPauloECompra(int $quantidade = 1): Product
    {
        $produto = Product::factory()->create(['price' => 100, 'stock' => 5, 'is_active' => true]);

        $this->get("/produtos/{$produto->slug}", self::SAO_PAULO)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('product.final_price', 109.94));

        app()->forgetScopedInstances();
        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => $quantidade], self::SAO_PAULO);
        app()->forgetScopedInstances();

        return $produto;
    }

    private function entrega(string $cep, array $extra = []): array
    {
        return array_merge([
            'shipping_method_id' => $this->frete->id,
            'guest' => ['name' => 'Maria Cliente', 'email' => 'maria@exemplo.com', 'cpf' => '123.456.789-09', 'phone' => '(11) 99999-0000'],
            'new_address' => [
                'recipient_name' => 'Maria Cliente', 'phone' => '(11) 99999-0000', 'zip' => $cep,
                'street' => 'Rua A', 'number' => '20', 'neighborhood' => 'Centro', 'city' => 'Cidade', 'state' => str_starts_with($cep, '0') ? 'SP' : 'CE',
            ],
        ], $extra);
    }

    private function pagarComPix(string $cep, array $extra = []): Order
    {
        Mail::fake();
        $this->mock(MercadoPagoPaymentService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createPixPayment')->once()->andReturn([
                'id' => 501, 'status' => 'pending',
                'point_of_interaction' => ['transaction_data' => ['qr_code' => 'pix', 'qr_code_base64' => 'abc']],
            ]);
        });

        app()->forgetScopedInstances();
        $this->postJson('/finalizacao/entrega', $this->entrega($cep, $extra), self::SAO_PAULO)->assertOk();
        app()->forgetScopedInstances();
        $this->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true], self::SAO_PAULO)->assertOk();

        return Order::with('items')->firstOrFail();
    }

    public function test_cep_da_mesma_regiao_fica_gratis_sem_diferenca(): void
    {
        $this->navegaDeSaoPauloECompra();

        $this->getJson('/finalizacao/entrega-gratis?cep=01310100', self::SAO_PAULO)->assertOk()
            ->assertJsonPath('subtotal', 109.94)
            ->assertJsonPath('diferenca_frete', 0)
            ->assertJsonPath('entregas.0.id', 'gratis')
            ->assertJsonPath('entregas.0.preco', 0);

        $order = $this->pagarComPix(self::CEP_SP);
        $this->assertEquals(109.94, (float) $order->items->first()->product_price);
        $this->assertEquals(0.0, (float) $order->shipping_cost);
        $this->assertEqualsWithDelta(round(109.94 - (float) $order->pix_discount_amount, 2), (float) $order->total, 0.001);
    }

    public function test_cep_de_regiao_mais_cara_cobra_a_diferenca_como_frete_sem_mudar_o_preco(): void
    {
        $this->navegaDeSaoPauloECompra();

        // Preço do produto não muda; o CE pede PAC R$ 20 e o preço já tem R$ 9,94.
        $this->getJson('/finalizacao/entrega-gratis?cep=60025000', self::SAO_PAULO)->assertOk()
            ->assertJsonPath('subtotal', 109.94)
            ->assertJsonPath('embutido_fixo', 9.94)
            ->assertJsonPath('pac_real', 20)
            ->assertJsonPath('diferenca_frete', 10.06);

        // A mesma cotação de novo (trava do frete gravada) segue com a diferença.
        app()->forgetScopedInstances();
        $this->getJson('/finalizacao/entrega-gratis?cep=60025000', self::SAO_PAULO)->assertOk()
            ->assertJsonPath('diferenca_frete', 10.06);

        // Pedido: produto pelo preço visto + R$ 10,06 de frete; o Pix não desconta o frete.
        $order = $this->pagarComPix(self::CEP_CE);
        $this->assertEquals(109.94, (float) $order->items->first()->product_price);
        $this->assertEquals(10.06, (float) $order->shipping_cost);
        $this->assertEqualsWithDelta(round(109.94 + 10.06 - (float) $order->pix_discount_amount, 2), (float) $order->total, 0.001);
    }

    public function test_kit_soma_o_frete_por_unidade_e_usa_a_caixa_unica(): void
    {
        $this->navegaDeSaoPauloECompra(2);

        // 2 × R$ 9,94 = R$ 19,88 no preço; a caixa única no CE sai R$ 20 (PAC).
        $this->getJson('/finalizacao/entrega-gratis?cep=60025000', self::SAO_PAULO)->assertOk()
            ->assertJsonPath('subtotal', 219.88)
            ->assertJsonPath('embutido_fixo', 19.88)
            ->assertJsonPath('pac_real', 20)
            ->assertJsonPath('diferenca_frete', 0.12)
            ->assertJsonPath('desconto_plataforma', 0);

        $order = $this->pagarComPix(self::CEP_CE);
        $this->assertEquals(0.12, (float) $order->shipping_cost);
    }

    public function test_entrega_expressa_cobra_so_o_que_passa_do_embutido(): void
    {
        $this->navegaDeSaoPauloECompra();

        // SEDEX R$ 30 no CE menos os R$ 9,94 que já estão no preço.
        $this->getJson('/finalizacao/entrega-gratis?cep=60025000', self::SAO_PAULO)->assertOk()
            ->assertJsonPath('entregas.1.id', 'expressa')
            ->assertJsonPath('entregas.1.tipo', 'sedex')
            ->assertJsonPath('entregas.1.preco', 20.06);

        $order = $this->pagarComPix(self::CEP_CE, ['turbo' => true]);
        $this->assertEquals(109.94, (float) $order->items->first()->product_price);
        $this->assertEquals(20.06, (float) $order->shipping_cost);
        $this->assertSame(FreteEmbutido::TURBO, $order->shipping_carrier_name);
    }
}
