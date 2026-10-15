<?php

namespace Tests\Feature\BeautyKora;

use App\Models\MelhorEnvioToken;
use App\Models\User;
use App\Modules\BeautyKora\Models\BeautyKoraCliente;
use App\Modules\BeautyKora\Models\BeautyKoraPedido;
use App\Modules\BeautyKora\Models\BeautyKoraProduto;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Coupon;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Services\CheckoutOutroSite;
use App\Modules\Checkout\Support\FreteEmbutido;
use App\Modules\Fiscal\Models\Company;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
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
        // Não clonado: aqui conta a entrega; o +5% do clonado está em PrecoRegiaoBeautyKoraTest.
        BeautyKoraProduto::create(['product_id' => $this->produto->id, 'ordem' => 1, 'ativo' => true, 'clonado' => false]);
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

    /**
     * Caixa de 600 g cujo preço já paga R$ 20 de PAC (8 dias), com TURBO de
     * R$ 25, e o Melhor Envio cotando Loggi Express a R$ $loggi (3 dias) e
     * Jadlog (fora da lista, não aparece).
     */
    private function comLoggi(float $loggi): void
    {
        $this->partialMock(FreteEmbutido::class, fn ($mock) => $mock->shouldReceive('entregaDoCarrinho')->andReturn([
            'prazo' => 8, 'turbo' => ['preco' => 25.0, 'prazo' => 4, 'dias_a_menos' => 4], 'estimado' => false, 'desconto_plataforma' => 0.0,
            'servico' => 'pac', 'custo' => 20.0, 'peso_g' => 600,
        ]));
        Company::create([
            'razao_social' => 'Kazakora Comércio Ltda', 'nome_fantasia' => 'KazaKora', 'cnpj' => '65604590000107',
            'regime_tributario' => Company::REGIME_SIMPLES_NACIONAL, 'phone' => '(11) 91234-5678', 'email' => 'contato@kazakora.com',
            'zip' => '03187-040', 'street' => 'Rua da Loja', 'number' => '100', 'neighborhood' => 'Centro', 'city' => 'São Paulo', 'state' => 'SP',
        ]);
        MelhorEnvioToken::create([
            'account_label' => 'KazaKora', 'access_token' => 'token-me', 'refresh_token' => 'refresh-me',
            'token_expires_at' => now()->addDays(10), 'scopes' => ['shipping-calculate'],
        ]);
        config(['services.melhorenvio.api_base_url' => 'https://melhorenvio.test/api/v2']);
        Http::fake(['melhorenvio.test/*' => Http::response([
            ['id' => 3, 'name' => '.Package', 'price' => '14.94', 'delivery_time' => 7, 'company' => ['name' => 'Jadlog']],
            ['id' => 31, 'name' => 'Express', 'price' => (string) $loggi, 'delivery_time' => 3, 'company' => ['name' => 'Loggi']],
            ['id' => 32, 'name' => 'Coleta', 'error' => 'Transportadora não atende este trecho.', 'company' => ['name' => 'Loggi']],
        ])]);
    }

    public function test_fora_da_grande_sp_mostra_correios_turbo_e_loggi_pela_diferenca(): void
    {
        $this->comLoggi(27.5);

        $this->postJson('/api/beautykora/checkout/resumo', ['itens' => $this->itens(), 'cep' => self::CEP_FORA])
            ->assertOk()
            ->assertJsonPath('entrega.escolhida', 'correios')
            ->assertJsonCount(3, 'entrega.opcoes')
            ->assertJsonPath('entrega.opcoes.0.transportadora', 'Correios')
            ->assertJsonPath('entrega.opcoes.0.servico', 'PAC')
            ->assertJsonPath('entrega.opcoes.0.preco', 0)
            ->assertJsonPath('entrega.opcoes.0.prazo', 8)
            ->assertJsonPath('entrega.opcoes.1.id', 'turbo')
            ->assertJsonPath('entrega.opcoes.2.id', 'me:31')
            ->assertJsonPath('entrega.opcoes.2.transportadora', 'Loggi')
            ->assertJsonPath('entrega.opcoes.2.servico', 'Express')
            ->assertJsonPath('entrega.opcoes.2.preco', 750)
            ->assertJsonPath('entrega.opcoes.2.prazo', 3)
            ->assertJsonPath('total', 20000);

        $this->postJson('/api/beautykora/checkout/resumo', ['itens' => $this->itens(), 'cep' => self::CEP_FORA, 'frete' => 'me:31'])
            ->assertOk()
            ->assertJsonPath('entrega.escolhida', 'me:31')
            ->assertJsonPath('entrega.turbo_escolhido', false)
            ->assertJsonPath('frete', 750)
            ->assertJsonPath('total', 20750)
            ->assertJsonPath('total_pix', 19712);
    }

    public function test_loggi_mais_barata_que_o_frete_embutido_sai_gratis_e_vai_no_pedido(): void
    {
        $this->comLoggi(12.0);
        $this->mercadoPago(fn ($mock) => $mock->shouldReceive('createPixPayment')->once()
            ->withArgs(fn ($valor) => $valor === 190.0)
            ->andReturn(['id' => 79, 'status' => 'pending']));

        $this->postJson('/api/beautykora/checkout/resumo', ['itens' => $this->itens(), 'cep' => self::CEP_FORA])
            ->assertJsonPath('entrega.opcoes.2.preco', 0);

        $resposta = $this->postJson('/api/beautykora/checkout/pedido', $this->pedido([
            'frete' => 'me:31',
            'endereco' => ['zip' => self::CEP_FORA, 'city' => 'Belo Horizonte', 'state' => 'MG'],
        ]))->assertCreated();

        $order = Order::findOrFail($resposta->json('pedido.id'));
        $this->assertSame('Loggi Express', $order->shipping_carrier_name);
        $this->assertNull($order->shipping_method_id);
        $this->assertSame('0.00', $order->shipping_cost);
    }

    public function test_grande_sp_tem_full_primeiro_e_tambem_correios_e_loggi(): void
    {
        $this->comLoggi(27.5);

        $this->postJson('/api/beautykora/checkout/resumo', ['itens' => $this->itens(), 'cep' => self::CEP_FULL])
            ->assertOk()
            ->assertJsonPath('entrega.escolhida', 'full')
            ->assertJsonPath('entrega.opcoes.0.id', 'full')
            ->assertJsonPath('entrega.opcoes.1.id', 'correios')
            ->assertJsonPath('entrega.opcoes.2.id', 'me:31')
            ->assertJsonCount(3, 'entrega.opcoes')
            ->assertJsonPath('frete', 0);
    }

    public function test_opcao_de_entrega_que_sumiu_nao_vira_outra_calada(): void
    {
        $this->comTurbo();
        $this->mercadoPago(fn ($mock) => $mock->shouldReceive('createPixPayment')->never());

        $this->postJson('/api/beautykora/checkout/pedido', $this->pedido([
            'frete' => 'me:31',
            'endereco' => ['zip' => self::CEP_FORA, 'city' => 'Belo Horizonte', 'state' => 'MG'],
        ]))->assertStatus(409)->assertJsonPath('resumo.entrega.escolhida', 'correios');

        $this->assertSame(0, Order::count());
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

    public function test_fora_da_grande_sp_turbo_soma_o_frete_e_o_pix_desconta_do_total(): void
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
            ->assertJsonPath('desconto_pix', 1125)
            ->assertJsonPath('total_pix', 21375);
    }

    public function test_pedido_com_turbo_grava_o_sedex_no_frete(): void
    {
        $this->comTurbo();
        $this->mercadoPago(fn ($mock) => $mock->shouldReceive('createPixPayment')->once()
            ->withArgs(fn ($valor) => $valor === 213.75)
            ->andReturn(['id' => 77, 'status' => 'pending']));

        $resposta = $this->postJson('/api/beautykora/checkout/pedido', $this->pedido([
            'turbo' => true,
            'total_esperado' => 21375,
            'endereco' => ['zip' => self::CEP_FORA, 'city' => 'Belo Horizonte', 'state' => 'MG'],
        ]))->assertCreated();

        $order = Order::findOrFail($resposta->json('pedido.id'));
        $this->assertSame(Order::SITE_BEAUTYKORA, $order->site_origem);
        $this->assertSame(Order::PROJETO_BEAUTYKORA, $order->fresh()->projeto_origem);
        $this->assertSame(FreteEmbutido::TURBO, $order->shipping_carrier_name);
        $this->assertNull($order->shipping_method_id);
        $this->assertSame('25.00', $order->shipping_cost);
        $this->assertSame('11.25', $order->pix_discount_amount);
        $this->assertSame('213.75', $order->total);
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

    private function cupom(array $extra = []): Coupon
    {
        return Coupon::create([
            'code' => 'BELEZA10', 'name' => 'Beleza 10', 'discount_type' => Coupon::TYPE_PERCENTAGE, 'discount_value' => 10,
            'is_active' => true, ...$extra,
        ]);
    }

    public function test_cupom_sai_dos_produtos_e_o_pix_desconta_do_que_sobrou(): void
    {
        $this->cupom();

        $this->postJson('/api/beautykora/checkout/resumo', ['itens' => $this->itens(), 'cep' => self::CEP_FULL, 'cupom' => ' beleza10 '])
            ->assertOk()
            ->assertJsonPath('cupom.codigo', 'BELEZA10')
            ->assertJsonPath('cupom.descricao', '10% de desconto')
            ->assertJsonPath('cupom.desconto', 2000)
            ->assertJsonPath('cupom_erro', null)
            ->assertJsonPath('subtotal', 20000)
            ->assertJsonPath('total', 18000)
            ->assertJsonPath('desconto_pix', 900)
            ->assertJsonPath('total_pix', 17100);
    }

    public function test_cupom_que_nao_vale_volta_o_motivo_e_nao_desconta(): void
    {
        $this->cupom(['min_order_value' => 500]);

        $this->postJson('/api/beautykora/checkout/resumo', ['itens' => $this->itens(), 'cep' => self::CEP_FULL, 'cupom' => 'BELEZA10'])
            ->assertOk()
            ->assertJsonPath('cupom', null)
            ->assertJsonPath('cupom_erro', 'Este cupom vale para compras a partir de R$ 500,00.')
            ->assertJsonPath('total', 20000);

        $this->postJson('/api/beautykora/checkout/resumo', ['itens' => $this->itens(), 'cupom' => 'NAOEXISTE'])
            ->assertOk()
            ->assertJsonPath('cupom_erro', 'Cupom não encontrado. Confira o código.');
    }

    public function test_pedido_com_cupom_grava_o_codigo_e_o_desconto_total(): void
    {
        $this->cupom();
        $this->mercadoPago(fn ($mock) => $mock->shouldReceive('createPixPayment')->once()
            ->withArgs(fn ($valor) => $valor === 171.0)
            ->andReturn(['id' => 91, 'status' => 'pending']));

        $resposta = $this->postJson('/api/beautykora/checkout/pedido', $this->pedido(['cupom' => 'BELEZA10', 'total_esperado' => 17100]))
            ->assertCreated();

        $order = Order::findOrFail($resposta->json('pedido.id'));
        $this->assertSame('BELEZA10', $order->coupon_code);
        $this->assertSame('29.00', $order->discount_amount);
        $this->assertSame('9.00', $order->pix_discount_amount);
        $this->assertSame('171.00', $order->total);
    }

    public function test_cupom_que_expirou_antes_de_pagar_nao_cria_pedido(): void
    {
        $this->cupom(['expires_at' => now()->subDay()]);
        $this->mercadoPago(fn ($mock) => $mock->shouldNotReceive('createPixPayment'));

        $this->postJson('/api/beautykora/checkout/pedido', $this->pedido(['cupom' => 'BELEZA10', 'total_esperado' => 17100]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('cupom');

        $this->assertSame(0, Order::count());
    }

    public function test_cupom_de_um_por_cliente_ja_usado_pelo_email_nao_vale(): void
    {
        $this->cupom(['one_per_customer' => true]);
        $usuario = User::factory()->create(['email' => 'maria@exemplo.com']);
        Order::create([
            'user_id' => $usuario->id, 'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_STORE, 'coupon_code' => 'BELEZA10',
            'shipping_name' => 'X', 'shipping_phone' => '11999990000', 'shipping_zip' => '01000000', 'shipping_street' => 'Rua',
            'shipping_number' => '1', 'shipping_neighborhood' => 'Centro', 'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 100, 'shipping_cost' => 0, 'total' => 90,
        ]);

        $this->postJson('/api/beautykora/checkout/resumo', ['itens' => $this->itens(), 'cupom' => 'BELEZA10', 'email' => 'maria@exemplo.com'])
            ->assertOk()
            ->assertJsonPath('cupom_erro', 'Você já usou este cupom em outra compra.');
    }
}
