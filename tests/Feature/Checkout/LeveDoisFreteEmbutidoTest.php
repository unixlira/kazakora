<?php

namespace Tests\Feature\Checkout;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Jobs\CotarFreteDoPedidoJob;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Company;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * "Compre 1, o 2º sai por R$ X" com o frete embutido ligado (como na
 * produção): a caixa única das 2 unidades sai do cupom, porque o preço da 2ª
 * já paga o frete dela. O par custa exatamente 1 unidade + X.
 *
 * Correios de mentira: SEDEX R$ 9,94 em SP para qualquer peso.
 */
class LeveDoisFreteEmbutidoTest extends TestCase
{
    use RefreshDatabase;

    private const SAO_PAULO = ['CF-IPCountry' => 'BR', 'CF-Region-Code' => 'SP'];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-28 10:00', 'America/Sao_Paulo'));
        Bus::fake([CotarFreteDoPedidoJob::class]);
        config([
            'app.url' => 'https://kazakora.com',
            'frete_embutido.ativo' => true,
            'frete_embutido.transportadoras_melhor_envio' => [],
            'entrega_expressa.motoboy.valor_sp' => 0.0,
            'loja.simples.aliquota' => 4.5, 'ofertas.taxa_cartao' => 4.98, 'ofertas.taxa_pix' => 0.99, 'ofertas.frete_estimado' => 25,
            'services.correios.numero_usuario' => '123',
            'services.correios.codigo_acesso_contrato' => 'token-contrato',
            'services.correios.preco_base_url' => 'https://correios.test/preco',
            'services.correios.prazo_base_url' => 'https://correios.test/prazo',
        ]);
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        $this->withoutVite();
        Company::create([
            'razao_social' => 'Kazakora Comércio Ltda', 'nome_fantasia' => 'KazaKora', 'cnpj' => '65604590000107',
            'regime_tributario' => Company::REGIME_SIMPLES_NACIONAL, 'phone' => '(11) 91234-5678', 'email' => 'contato@kazakora.com',
            'zip' => '03187-040', 'street' => 'Rua da Loja', 'number' => '100', 'neighborhood' => 'Mooca', 'city' => 'São Paulo', 'state' => 'SP',
        ]);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'viacep.com.br')) {
                return Http::response([]);
            }
            if (! str_contains($request->url(), 'correios.test')) {
                return null;
            }
            if (str_contains($request->url(), '/preco/')) {
                return Http::response(collect($request['parametrosProduto'])->map(fn ($p) => [
                    'coProduto' => $p['coProduto'], 'nuRequisicao' => $p['nuRequisicao'], 'pcFinal' => $p['coProduto'] === '03220' ? '9,94' : '15,45',
                ])->all());
            }

            return Http::response(collect($request['parametrosPrazo'])->map(fn ($p) => [
                'coProduto' => $p['coProduto'], 'nuRequisicao' => $p['nuRequisicao'], 'prazoEntrega' => $p['coProduto'] === '03220' ? 3 : 8,
            ])->all());
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_par_sai_por_uma_unidade_mais_o_preco_da_segunda(): void
    {
        $this->artisan('loja:cupom-leve2');
        $frete = ShippingMethod::factory()->create(['price' => 0, 'is_active' => true]);
        $produto = Product::factory()->create(['price' => 150, 'cost_price' => 80, 'stock' => 5, 'is_active' => true, 'checkout_discount_percentage' => 0, 'discount_percentage' => null, 'discount_amount' => null]);

        $this->get("/produtos/{$produto->slug}", self::SAO_PAULO)->assertOk();
        app()->forgetScopedInstances();
        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 2], self::SAO_PAULO);
        app()->forgetScopedInstances();
        $this->get('/finalizacao', self::SAO_PAULO)->assertOk();
        app()->forgetScopedInstances();

        // Unidade na loja: 150 + 9,94 = 159,94. Bruto 159,94 − 138,90 = 21,04; a caixa
        // única (2 × 9,94 − 9,94 = 9,94) já devolve parte: o cupom fica com 11,10.
        $this->getJson('/finalizacao/entrega-gratis?cep=01310100', self::SAO_PAULO)->assertOk()
            ->assertJsonPath('desconto_plataforma', 9.94);
        app()->forgetScopedInstances();
        $this->postJson('/finalizacao/cupom', ['code' => 'LEVE22811'], self::SAO_PAULO)->assertOk()
            ->assertJsonPath('discount_amount', 11.1);

        Mail::fake();
        $this->mock(MercadoPagoPaymentService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createPixPayment')->once()->andReturn([
                'id' => 501, 'status' => 'pending',
                'point_of_interaction' => ['transaction_data' => ['qr_code' => 'pix', 'qr_code_base64' => 'abc']],
            ]);
        });
        app()->forgetScopedInstances();
        $this->postJson('/finalizacao/entrega', [
            'shipping_method_id' => $frete->id,
            'guest' => ['name' => 'Maria Cliente', 'email' => 'maria@exemplo.com', 'cpf' => '123.456.789-09', 'phone' => '(11) 99999-0000'],
            'new_address' => ['recipient_name' => 'Maria Cliente', 'phone' => '(11) 99999-0000', 'zip' => '01310-100', 'street' => 'Rua A', 'number' => '20', 'neighborhood' => 'Centro', 'city' => 'São Paulo', 'state' => 'SP'],
        ], self::SAO_PAULO)->assertOk();
        app()->forgetScopedInstances();
        $this->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true], self::SAO_PAULO)->assertOk();

        $order = Order::with('items')->firstOrFail();
        $this->assertSame('LEVE22811', $order->coupon_code);
        $this->assertEquals(319.88, (float) $order->subtotal);
        $this->assertEquals(9.94, (float) $order->desconto_plataforma_amount);
        // Total = 159,94 + 138,90 (+ frete do CEP, zero aqui).
        $this->assertEquals(round(159.94 + 138.90 + (float) $order->shipping_cost, 2), (float) $order->total);
        $this->assertEquals(11.10, (float) $order->items->first()->leve2_desconto);
        $this->assertEquals(159.94, (float) $order->items->first()->leve2_preco_normal);
    }
}
