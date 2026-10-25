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
 * Preço fixo no KazaKora (pedido 2026-10-25): clientes das campanhas viam um
 * preço no card e outro no checkout, porque o frete embutido mudava com o
 * CEP/IP. Agora o frete somado ao preço é o do CEP de referência
 * (frete_embutido.cep_fixo) e o card, a página do produto, o checkout e o
 * pedido mostram o mesmo preço "por" e o mesmo riscado para qualquer cliente.
 */
class PrecoFixoKazaKoraTest extends TestCase
{
    use RefreshDatabase;

    private const CEP_CE = '60025-000';

    private ShippingMethod $frete;

    protected function setUp(): void
    {
        parent::setUp();
        // Referência em São Paulo: SEDEX R$ 9,94 (o mais barato) vai no preço.
        config(['frete_embutido.ativo' => true, 'frete_embutido.cep_fixo' => '01310100']);
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        $this->frete = ShippingMethod::factory()->create(['price' => 0, 'is_active' => true]);

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

        // Ceará: PAC R$ 20 em 8 dias, SEDEX R$ 30 em 3. São Paulo: SEDEX R$ 9,94.
        Http::fake(function (Request $request) {
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

    private function produto(): Product
    {
        // R$ 125 com 20% de desconto: "por" R$ 100 + frete, riscado R$ 125 + frete.
        return Product::factory()->create(['price' => 125, 'discount_percentage' => 20, 'discount_amount' => null, 'stock' => 5, 'is_active' => true]);
    }

    public function test_mesmo_preco_e_mesmo_riscado_sem_regiao_pelo_ip_e_com_cep_de_outro_estado(): void
    {
        config(['app.url' => 'https://kazakora.com']);
        $produto = $this->produto();
        $precos = fn (AssertableInertia $page) => $page->where('product.final_price', 109.94)->where('product.preco_de', 134.94);

        $this->get("/produtos/{$produto->slug}")->assertInertia($precos);

        app()->forgetScopedInstances();
        $this->get("/produtos/{$produto->slug}", ['CF-IPCountry' => 'BR', 'CF-Region-Code' => 'CE'])->assertInertia($precos);

        // CEP do Ceará digitado no produto: preço igual, só o prazo é do cliente.
        app()->forgetScopedInstances();
        $this->getJson('/frete/prazo?cep=60025000&produto='.$produto->id)
            ->assertOk()
            ->assertJsonPath('final_price', 109.94);
        app()->forgetScopedInstances();
        $this->get("/produtos/{$produto->slug}")->assertInertia($precos);
    }

    public function test_checkout_e_pedido_saem_pelo_preco_do_card_com_cep_de_outro_estado(): void
    {
        Mail::fake();
        $produto = $this->produto();
        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 1]);

        $this->getJson('/finalizacao/entrega-gratis?cep=60025000')
            ->assertOk()
            ->assertJsonPath('subtotal', 109.94)
            ->assertJsonPath('prazo', 8)
            ->assertJsonPath('desconto_plataforma', 0);

        $this->mock(MercadoPagoPaymentService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createPixPayment')->once()->andReturn([
                'id' => 77, 'status' => 'pending',
                'point_of_interaction' => ['transaction_data' => ['qr_code' => 'pix', 'qr_code_base64' => 'abc']],
            ]);
        });

        $this->postJson('/finalizacao/entrega', [
            'shipping_method_id' => $this->frete->id,
            'guest' => ['name' => 'Maria Cliente', 'email' => 'maria@exemplo.com', 'cpf' => '123.456.789-09', 'phone' => '(85) 99999-0000'],
            'new_address' => [
                'recipient_name' => 'Maria Cliente', 'phone' => '(85) 99999-0000', 'zip' => self::CEP_CE,
                'street' => 'Rua A', 'number' => '20', 'neighborhood' => 'Centro', 'city' => 'Fortaleza', 'state' => 'CE',
            ],
        ])->assertOk();
        $this->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true])->assertOk();

        $order = Order::with('items')->firstOrFail();
        $this->assertEquals(109.94, (float) $order->items->first()->product_price);
        $this->assertEquals(0.0, (float) $order->shipping_cost);
        $this->assertEquals(9.94, (float) $order->items->first()->frete_embutido);
    }

    public function test_sem_cep_fixo_volta_ao_frete_pela_regiao(): void
    {
        config(['frete_embutido.cep_fixo' => null]);
        $produto = $this->produto();

        $this->getJson('/frete/prazo?cep=60025000&produto='.$produto->id)
            ->assertOk()
            ->assertJsonPath('final_price', 120);
    }
}
