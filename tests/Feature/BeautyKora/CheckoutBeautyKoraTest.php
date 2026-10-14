<?php

namespace Tests\Feature\BeautyKora;

use App\Modules\BeautyKora\Models\BeautyKoraCliente;
use App\Modules\BeautyKora\Models\BeautyKoraPedido;
use App\Modules\BeautyKora\Models\BeautyKoraProduto;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Services\CheckoutOutroSite;
use App\Modules\Checkout\Support\FreteEmbutido;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Checkout do Beauty Kora pelo Mercado Pago da KazaKora (pedido 2026-10-14):
 * pedido da loja com site_origem beautykora, entrega FULL na Grande SP ou
 * grátis com a opção TURBO fora dela, e "Meus pedidos" do cliente logado.
 */
class CheckoutBeautyKoraTest extends TestCase
{
    use RefreshDatabase;

    private const CEP_FULL = '01310-100';

    private const CEP_FORA = '30130-000';

    private Product $produto;

    protected function setUp(): void
    {
        parent::setUp();
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        config(['loja.desconto_pix' => 5]);
        Mail::fake();
        Bus::fake();

        $this->produto = Product::factory()->create([
            'sku' => 'BEL-BLU-01', 'name' => 'Blush Líquido 10ml', 'price' => 100.00, 'stock' => 5, 'is_active' => true,
            'discount_percentage' => null, 'discount_amount' => null,
        ]);
        BeautyKoraProduto::create(['product_id' => $this->produto->id, 'ordem' => 1, 'ativo' => true]);
    }

    private function itens(int $quantidade = 2): array
    {
        return [['variante' => 'kzv-'.$this->produto->id, 'quantidade' => $quantidade]];
    }

    private function pedido(array $extra = []): array
    {
        return array_replace_recursive([
            'itens' => $this->itens(),
            'total_esperado' => 19000,
            'cliente' => ['name' => 'Maria da Silva Souza', 'email' => 'maria@exemplo.com', 'cpf' => '123.456.789-09', 'phone' => '11988887777'],
            'endereco' => ['zip' => self::CEP_FULL, 'street' => 'Av. Paulista', 'number' => '1000', 'neighborhood' => 'Bela Vista', 'city' => 'São Paulo', 'state' => 'sp'],
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

    /** TURBO de R$ 25 (SEDEX chega 4 dias antes) fora da Grande SP. */
    private function comTurbo(): void
    {
        $this->partialMock(FreteEmbutido::class, fn ($mock) => $mock->shouldReceive('entregaDoCarrinho')->andReturn([
            'prazo' => 8, 'turbo' => ['preco' => 25.0, 'prazo' => 4, 'dias_a_menos' => 4], 'estimado' => false, 'desconto_plataforma' => 0.0,
        ]));
    }

    public function test_cep_da_grande_sp_tem_entrega_full_sem_turbo(): void
    {
        $this->comTurbo();

        $this->postJson('/api/beautykora/checkout/resumo', ['itens' => $this->itens(), 'cep' => self::CEP_FULL])
            ->assertOk()
            ->assertJsonPath('entrega.full.local', 'São Paulo')
            ->assertJsonPath('entrega.turbo', null)
            ->assertJsonPath('frete', 0)
            ->assertJsonPath('total', 20000)
            ->assertJsonPath('total_pix', 19000);
    }

    public function test_fora_da_grande_sp_turbo_soma_o_frete_e_o_pix_nao_desconta_dele(): void
    {
        $this->comTurbo();

        $this->postJson('/api/beautykora/checkout/resumo', ['itens' => $this->itens(), 'cep' => self::CEP_FORA])
            ->assertOk()
            ->assertJsonPath('entrega.full', null)
            ->assertJsonPath('entrega.turbo.preco', 2500)
            ->assertJsonPath('entrega.turbo.dias_a_menos', 4)
            ->assertJsonPath('prazo_dias', 8)
            ->assertJsonPath('total', 20000);

        $this->postJson('/api/beautykora/checkout/resumo', ['itens' => $this->itens(), 'cep' => self::CEP_FORA, 'turbo' => true])
            ->assertOk()
            ->assertJsonPath('entrega.turbo_escolhido', true)
            ->assertJsonPath('frete', 2500)
            ->assertJsonPath('total', 22500)
            ->assertJsonPath('total_pix', 21500);
    }

    public function test_pedido_com_turbo_grava_o_sedex_no_frete(): void
    {
        $this->comTurbo();
        $this->mercadoPago(fn ($mock) => $mock->shouldReceive('createPixPayment')->once()
            ->withArgs(fn ($valor) => $valor === 215.0)
            ->andReturn(['id' => 77, 'status' => 'pending']));

        $resposta = $this->postJson('/api/beautykora/checkout/pedido', $this->pedido([
            'turbo' => true,
            'total_esperado' => 21500,
            'endereco' => ['zip' => self::CEP_FORA, 'city' => 'Belo Horizonte', 'state' => 'MG'],
        ]))->assertCreated();

        $order = Order::findOrFail($resposta->json('pedido.id'));
        $this->assertSame(Order::SITE_BEAUTYKORA, $order->site_origem);
        $this->assertSame(FreteEmbutido::TURBO, $order->shipping_carrier_name);
        $this->assertNull($order->shipping_method_id);
        $this->assertSame('25.00', $order->shipping_cost);
        $this->assertSame('215.00', $order->total);
    }

    public function test_turbo_pedido_na_area_full_e_ignorado(): void
    {
        $this->comTurbo();
        $this->mercadoPago(fn ($mock) => $mock->shouldReceive('createPixPayment')->once()->andReturn(['id' => 78, 'status' => 'pending']));

        $resposta = $this->postJson('/api/beautykora/checkout/pedido', $this->pedido(['turbo' => true]))->assertCreated();

        $this->assertSame('0.00', Order::findOrFail($resposta->json('pedido.id'))->shipping_cost);
    }

    public function test_produto_que_nao_esta_no_beauty_kora_e_recusado(): void
    {
        $outro = Product::factory()->create(['is_active' => true, 'stock' => 3]);

        $this->postJson('/api/beautykora/checkout/resumo', ['itens' => [['variante' => 'kzv-'.$outro->id, 'quantidade' => 1]]])
            ->assertStatus(422)
            ->assertJsonPath('errors.itens.0', 'Este produto não está mais à venda no Beauty Kora.');
    }

    public function test_pix_pago_e_conferido_pelo_token_do_beauty_kora(): void
    {
        $this->mercadoPago(function ($mock) {
            $mock->shouldReceive('createPixPayment')->once()
                ->andReturn(['id' => 555, 'status' => 'pending', 'point_of_interaction' => ['transaction_data' => ['qr_code' => 'pix-copia', 'qr_code_base64' => 'abc']]]);
            $mock->shouldReceive('retrievePayment')->with('555')->andReturn(['status' => 'approved']);
        });

        $resposta = $this->postJson('/api/beautykora/checkout/pedido', $this->pedido())
            ->assertCreated()
            ->assertJsonPath('pix.copia_e_cola', 'pix-copia')
            ->assertJsonPath('pedido.total', 19000);

        $order = Order::findOrFail($resposta->json('pedido.id'));
        $this->assertSame($resposta->json('token'), CheckoutOutroSite::token($order));

        // O pedido do Beauty Kora não abre pela rota do FerraKora.
        $this->getJson("/api/ferrakora/checkout/pedido/{$order->id}?token=".$resposta->json('token'))->assertNotFound();
        $this->getJson("/api/beautykora/checkout/pedido/{$order->id}?token=".$resposta->json('token'))
            ->assertOk()
            ->assertJsonPath('pedido.pago', true);
    }

    public function test_cliente_logado_ve_o_pedido_em_meus_pedidos_com_a_situacao_da_kazakora(): void
    {
        $cliente = BeautyKoraCliente::query()->create(['name' => 'Maria', 'email' => 'maria@exemplo.com', 'password' => 'segredo123']);
        $token = $cliente->createToken('site')->plainTextToken;
        $this->mercadoPago(fn ($mock) => $mock->shouldReceive('createPixPayment')->once()->andReturn(['id' => 9, 'status' => 'pending']));

        $resposta = $this->withToken($token)->postJson('/api/beautykora/checkout/pedido', $this->pedido())->assertCreated();

        $pedido = BeautyKoraPedido::query()->sole();
        $this->assertSame($resposta->json('pedido.id'), $pedido->order_id);
        $this->assertSame('kzv-'.$this->produto->id, $pedido->variante_id);
        $this->assertSame(2, $pedido->quantidade);

        $this->withToken($token)->getJson('/api/beautykora/pedidos')->assertOk()->assertJsonPath('orders.0.status', 'pending');
        Order::findOrFail($pedido->order_id)->update(['status' => Order::STATUS_PAID]);
        $this->withToken($token)->getJson('/api/beautykora/pedidos')->assertOk()->assertJsonPath('orders.0.status', 'paid');
    }

    public function test_sem_login_nao_grava_meus_pedidos(): void
    {
        $this->mercadoPago(fn ($mock) => $mock->shouldReceive('createPixPayment')->once()->andReturn(['id' => 10, 'status' => 'pending']));

        $this->postJson('/api/beautykora/checkout/pedido', $this->pedido())->assertCreated();

        $this->assertSame(0, BeautyKoraPedido::count());
    }
}
