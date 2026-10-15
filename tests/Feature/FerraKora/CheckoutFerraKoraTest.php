<?php

namespace Tests\Feature\FerraKora;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use App\Modules\FerraKora\Services\CheckoutFerraKora;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Checkout próprio do FerraKora (pedido 2026-10-14): pedido da KazaKora com
 * site_origem ferrakora, Pix e cartão pelo Mercado Pago, sem sessão.
 */
class CheckoutFerraKoraTest extends TestCase
{
    use RefreshDatabase;

    private Product $produto;

    protected function setUp(): void
    {
        parent::setUp();
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        config(['loja.desconto_pix' => 5]);
        Mail::fake();
        Bus::fake();

        $this->produto = Product::factory()->create([
            'sku' => 'FER-TRE-5M', 'name' => 'Trena Emborrachada 5m', 'price' => 100.00, 'stock' => 5, 'is_active' => true,
            'discount_percentage' => null, 'discount_amount' => null,
        ]);
        FerraKoraProduto::create(['product_id' => $this->produto->id, 'categoria' => 'Trenas', 'ordem' => 1, 'ativo' => true]);
    }

    private function itens(int $quantidade = 1): array
    {
        return [['variante' => 'kzv-'.$this->produto->id, 'quantidade' => $quantidade]];
    }

    private function pedido(array $extra = []): array
    {
        return array_replace_recursive([
            'itens' => $this->itens(2),
            'total_esperado' => 19000,
            'cliente' => ['name' => 'Maria da Silva Souza', 'email' => 'maria@exemplo.com', 'cpf' => '123.456.789-09', 'phone' => '11988887777'],
            'endereco' => ['zip' => '01310-100', 'street' => 'Av. Paulista', 'number' => '1000', 'neighborhood' => 'Bela Vista', 'city' => 'São Paulo', 'state' => 'sp'],
            'pagamento' => ['metodo' => 'pix'],
        ], $extra);
    }

    private function mercadoPago(callable $configurar): void
    {
        $this->mock(MercadoPagoPaymentService::class, function ($mock) use ($configurar) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $configurar($mock);
        });
    }

    public function test_resumo_com_desconto_no_pix(): void
    {
        $this->postJson('/api/ferrakora/checkout/resumo', ['itens' => $this->itens(2)])
            ->assertOk()
            ->assertJsonPath('itens.0.nome', 'Trena Emborrachada 5m')
            ->assertJsonPath('itens.0.preco_unitario', 10000)
            ->assertJsonPath('subtotal', 20000)
            ->assertJsonPath('total', 20000)
            ->assertJsonPath('desconto_pix', 1000)
            ->assertJsonPath('total_pix', 19000)
            ->assertJsonPath('frete', 0);
    }

    public function test_produto_fora_do_ferrakora_e_sem_estoque_sao_recusados(): void
    {
        $outro = Product::factory()->create(['is_active' => true, 'stock' => 3]);

        $this->postJson('/api/ferrakora/checkout/resumo', ['itens' => [['variante' => 'kzv-'.$outro->id, 'quantidade' => 1]]])->assertStatus(422);
        $this->postJson('/api/ferrakora/checkout/resumo', ['itens' => $this->itens(6)])->assertStatus(409)->assertJsonPath('estoque', true);
    }

    public function test_pix_cria_pedido_da_loja_marcado_ferrakora_e_paga_pelo_status(): void
    {
        $this->mercadoPago(function ($mock) {
            $mock->shouldReceive('createPixPayment')->once()
                ->withArgs(fn ($valor, $pagador) => $valor === 190.0 && $pagador['identification']['number'] === '12345678909')
                ->andReturn(['id' => 555, 'status' => 'pending', 'point_of_interaction' => ['transaction_data' => ['qr_code' => 'pix-copia', 'qr_code_base64' => 'abc']]]);
            $mock->shouldReceive('retrievePayment')->with('555')->andReturn(['status' => 'approved']);
        });

        $resposta = $this->postJson('/api/ferrakora/checkout/pedido', $this->pedido())
            ->assertCreated()
            ->assertJsonPath('pix.copia_e_cola', 'pix-copia')
            ->assertJsonPath('pedido.total', 19000)
            ->assertJsonPath('pedido.pago', false);

        $order = Order::findOrFail($resposta->json('pedido.id'));
        $this->assertSame(Order::ORIGIN_STORE, $order->origin);
        $this->assertSame(Order::SITE_FERRAKORA, $order->site_origem);
        $this->assertSame('SP', $order->shipping_state);
        $this->assertSame('10.00', $order->pix_discount_amount);
        $this->assertSame(3, $this->produto->fresh()->stock);
        $this->assertSame('maria@exemplo.com', $order->user->email);
        $this->assertSame($resposta->json('token'), CheckoutFerraKora::token($order));

        $this->getJson("/api/ferrakora/checkout/pedido/{$order->id}?token=errado")->assertNotFound();
        $this->getJson("/api/ferrakora/checkout/pedido/{$order->id}?token=".$resposta->json('token'))
            ->assertOk()
            ->assertJsonPath('pedido.pago', true);
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_vitrine_e_resumo_nao_gastam_o_limite_do_pedido(): void
    {
        $this->mercadoPago(fn ($mock) => $mock->shouldReceive('createPixPayment')->andReturn(['id' => 10, 'status' => 'pending']));

        for ($i = 0; $i < 12; $i++) {
            $this->postJson('/api/ferrakora/checkout/resumo', ['itens' => $this->itens()])->assertOk();
        }

        $this->postJson('/api/ferrakora/checkout/pedido', $this->pedido())->assertCreated();
    }

    public function test_total_diferente_da_tela_nao_grava_nada(): void
    {
        $this->mercadoPago(fn ($mock) => $mock->shouldNotReceive('createPixPayment'));

        $this->postJson('/api/ferrakora/checkout/pedido', $this->pedido(['total_esperado' => 18000]))
            ->assertStatus(409)
            ->assertJsonPath('resumo.total_pix', 19000);

        $this->assertSame(0, Order::count());
        $this->assertSame(5, $this->produto->fresh()->stock);
        $this->assertSame(0, User::count());
    }

    public function test_email_de_outra_conta_com_outro_cpf_e_recusado(): void
    {
        User::factory()->create(['email' => 'maria@exemplo.com', 'cpf' => '52998224725']);
        $this->mercadoPago(fn ($mock) => $mock->shouldNotReceive('createPixPayment'));

        $this->postJson('/api/ferrakora/checkout/pedido', $this->pedido())
            ->assertStatus(422)
            ->assertJsonValidationErrors('cliente.email');
        $this->assertSame(0, Order::count());
    }

    public function test_mesma_conta_por_email_e_cpf_e_reaproveitada(): void
    {
        $conta = User::factory()->create(['email' => 'maria@exemplo.com', 'cpf' => '123.456.789-09']);
        $this->mercadoPago(fn ($mock) => $mock->shouldReceive('createPixPayment')->andReturn(['id' => 9, 'status' => 'pending']));

        $resposta = $this->postJson('/api/ferrakora/checkout/pedido', $this->pedido())->assertCreated();

        $this->assertSame($conta->id, Order::find($resposta->json('pedido.id'))->user_id);
    }

    public function test_cartao_recusado_desfaz_tudo(): void
    {
        $this->mercadoPago(fn ($mock) => $mock->shouldReceive('createOrder')->once()->andReturn([
            'id' => 'ORD1', 'transactions' => ['payments' => [['id' => 'PAY1', 'status' => 'failed', 'status_detail' => 'cc_rejected']]],
        ]));

        $this->postJson('/api/ferrakora/checkout/pedido', $this->pedido([
            'total_esperado' => 20000,
            'pagamento' => ['metodo' => 'card', 'mp_card_token' => 'tok', 'mp_card_installments' => 1, 'mp_payment_method_id' => 'visa'],
        ]))->assertStatus(422);

        $this->assertSame(0, Order::count());
        $this->assertSame(5, $this->produto->fresh()->stock);
    }

    public function test_cartao_aprovado_fica_pago(): void
    {
        $this->mercadoPago(fn ($mock) => $mock->shouldReceive('createOrder')->once()->andReturn([
            'id' => 'ORD2', 'transactions' => ['payments' => [['id' => 'PAY2', 'status' => 'processed', 'status_detail' => 'accredited']]],
        ]));

        $this->postJson('/api/ferrakora/checkout/pedido', $this->pedido([
            'total_esperado' => 20000,
            'pagamento' => ['metodo' => 'card', 'mp_card_token' => 'tok', 'mp_card_installments' => 3, 'mp_payment_method_id' => 'master'],
        ]))->assertCreated()->assertJsonPath('pedido.pago', true)->assertJsonPath('pix', null);
    }

    public function test_boleto_cria_pedido_sem_desconto_do_pix_e_devolve_linha_e_pdf(): void
    {
        $this->mercadoPago(function ($mock) {
            $mock->shouldReceive('createBoletoPayment')->once()
                ->withArgs(fn ($valor, $pagador) => $valor === 200.0
                    && $pagador['identification']['number'] === '12345678909'
                    && $pagador['address']['zip_code'] === '01310100'
                    && $pagador['address']['federal_unit'] === 'SP')
                ->andReturn([
                    'id' => 777, 'status' => 'pending', 'date_of_expiration' => '2026-10-17T23:59:59.000-03:00',
                    'transaction_details' => ['digitable_line' => '23790.12345 60000.000000 00000.000000 1 00000000020000', 'external_resource_url' => 'https://www.mercadopago.com.br/payments/777/ticket'],
                ]);
            $mock->shouldReceive('retrievePayment')->with('777')->andReturn(['status' => 'pending']);
        });

        $this->postJson('/api/ferrakora/checkout/resumo', ['itens' => $this->itens(2)])->assertJsonPath('boleto_dias_vencimento', 3);

        $resposta = $this->postJson('/api/ferrakora/checkout/pedido', $this->pedido(['total_esperado' => 20000, 'pagamento' => ['metodo' => 'boleto']]))
            ->assertCreated()
            ->assertJsonPath('pix', null)
            ->assertJsonPath('boleto.linha_digitavel', '23790.12345 60000.000000 00000.000000 1 00000000020000')
            ->assertJsonPath('boleto.url', 'https://www.mercadopago.com.br/payments/777/ticket')
            ->assertJsonPath('boleto.vencimento', '2026-10-17T23:59:59.000-03:00')
            ->assertJsonPath('pedido.total', 20000)
            ->assertJsonPath('pedido.pago', false);

        $order = Order::findOrFail($resposta->json('pedido.id'));
        $this->assertSame(Order::SITE_FERRAKORA, $order->site_origem);
        $this->assertSame('0.00', $order->pix_discount_amount);
        $this->assertTrue($order->payments()->firstOrFail()->boletoPendente());
        Mail::assertSent(\App\Modules\Checkout\Mail\BoletoAguardandoPagamento::class, fn ($mail) => $mail->hasTo('maria@exemplo.com'));

        $this->getJson("/api/ferrakora/checkout/pedido/{$order->id}?token=".$resposta->json('token'))
            ->assertOk()
            ->assertJsonPath('pedido.pago', false)
            ->assertJsonPath('pedido.cancelado', false);
    }
}
