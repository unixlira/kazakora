<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\LeadCheckout;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Support\LeadsDoCheckout;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Carrinho abandonado (pedido do Lira 2026-10-24): "Ir para Entrega" grava o
 * contato e os itens em lead_checkout, sem duplicar, e o pedido converte.
 */
class LeadCheckoutTest extends TestCase
{
    use RefreshDatabase;

    private ShippingMethod $frete;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        $this->frete = ShippingMethod::factory()->create(['price' => 0, 'is_active' => true]);
    }

    private function noCarrinho(float $preco = 105, int $quantidade = 1, array $extra = []): Product
    {
        $product = Product::factory()->create(['price' => $preco, 'stock' => 5, 'is_active' => true, ...$extra]);
        $this->post('/carrinho', ['product_id' => $product->id, 'quantity' => $quantidade]);

        return $product;
    }

    private function dados(array $extra = []): array
    {
        return array_merge([
            'nome' => 'Maria Cliente Teste',
            'email' => 'Maria@Exemplo.com ',
            'cpf' => '123.456.789-09',
            'telefone' => '(11) 99999-0000',
        ], $extra);
    }

    public function test_ir_para_entrega_grava_o_lead_com_os_itens_normalizados(): void
    {
        $product = $this->noCarrinho(50, 2, ['variation' => 'Azul']);

        $this->postJson('/finalizacao/lead', $this->dados())->assertNoContent();

        $lead = LeadCheckout::sole();
        $this->assertSame('kazakora', $lead->site);
        $this->assertSame('maria@exemplo.com', $lead->email);
        $this->assertSame('12345678909', $lead->cpf);
        $this->assertSame('11999990000', $lead->telefone);
        $this->assertSame('Maria Cliente Teste', $lead->nome);
        $this->assertSame(LeadCheckout::STATUS_ABERTO, $lead->status);
        $this->assertSame(LeadCheckout::ETAPA_ENTREGA, $lead->etapa);
        $this->assertNotNull($lead->session_id);
        $this->assertSame(2, $lead->quantidade_itens);
        $this->assertEquals(100.0, (float) $lead->subtotal);
        $this->assertSame($product->id, $lead->itens[0]['product_id']);
        $this->assertSame('Azul', $lead->itens[0]['variacao']);
        $this->assertSame(2, $lead->itens[0]['quantidade']);
        $this->assertEquals(50.0, $lead->itens[0]['preco_unitario']);
        $this->assertEquals(100.0, $lead->itens[0]['subtotal']);
    }

    public function test_clicar_varias_vezes_atualiza_o_mesmo_lead(): void
    {
        $this->noCarrinho();

        $this->postJson('/finalizacao/lead', $this->dados())->assertNoContent();
        $this->postJson('/finalizacao/lead', $this->dados(['nome' => 'Maria Cliente Corrigida']))->assertNoContent();
        $this->postJson('/finalizacao/lead', $this->dados(['nome' => 'Maria Cliente Corrigida', 'telefone' => '']))->assertNoContent();

        $lead = LeadCheckout::sole();
        $this->assertSame('Maria Cliente Corrigida', $lead->nome);
        // Telefone vazio depois não apaga o que já se sabia.
        $this->assertSame('11999990000', $lead->telefone);
    }

    public function test_mesmo_email_em_outra_sessao_reaproveita_o_lead_aberto(): void
    {
        $this->noCarrinho();
        $this->postJson('/finalizacao/lead', $this->dados())->assertNoContent();

        $this->flushSession();
        $this->noCarrinho(30);
        $this->postJson('/finalizacao/lead', $this->dados(['cpf' => '']))->assertNoContent();

        $this->assertSame(1, LeadCheckout::count());
        $this->assertEquals(30.0, (float) LeadCheckout::sole()->subtotal);
    }

    public function test_carrinho_vazio_ou_sem_contato_nao_grava_e_nao_da_erro(): void
    {
        $this->postJson('/finalizacao/lead', $this->dados())->assertNoContent();
        $this->assertSame(0, LeadCheckout::count());

        $this->noCarrinho();
        $this->postJson('/finalizacao/lead', ['nome' => 'Fulano de Tal Silva', 'email' => 'invalido', 'telefone' => '123'])->assertNoContent();
        $this->assertSame(0, LeadCheckout::count());
    }

    public function test_cpf_invalido_fica_de_fora_mas_o_lead_e_gravado(): void
    {
        $this->noCarrinho();

        $this->postJson('/finalizacao/lead', $this->dados(['cpf' => '111.111.111-11']))->assertNoContent();

        $this->assertNull(LeadCheckout::sole()->cpf);
    }

    public function test_falha_na_gravacao_nao_quebra_e_nao_loga_dado_pessoal(): void
    {
        $this->noCarrinho();
        Log::spy();
        \Illuminate\Support\Facades\Schema::drop('lead_checkout');

        $this->postJson('/finalizacao/lead', $this->dados())->assertNoContent();

        Log::shouldHaveReceived('warning')->withArgs(function (string $mensagem, array $contexto) {
            $texto = json_encode($contexto);

            return $mensagem === 'checkout.lead.falhou'
                && ! str_contains($texto, 'exemplo.com')
                && ! str_contains($texto, '12345678909')
                && ! str_contains($texto, '99999');
        })->once();
    }

    public function test_limite_por_minuto(): void
    {
        $this->noCarrinho();

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/finalizacao/lead', $this->dados())->assertNoContent();
        }

        $this->postJson('/finalizacao/lead', $this->dados())->assertStatus(429);
    }

    public function test_cliente_logado_usa_os_dados_do_cadastro(): void
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'name' => 'Ana Logada da Silva', 'email' => 'ana@exemplo.com', 'phone' => '11988887777', 'cpf' => '12345678909']);
        $this->actingAs($user);
        $this->noCarrinho();

        $this->postJson('/finalizacao/lead', ['nome' => 'Ana Logada da Silva', 'telefone' => '(11) 98888-7777'])->assertNoContent();

        $lead = LeadCheckout::sole();
        $this->assertSame($user->id, $lead->user_id);
        $this->assertSame('ana@exemplo.com', $lead->email);
        $this->assertSame('12345678909', $lead->cpf);
    }

    public function test_pedido_criado_converte_o_lead_e_liga_o_order_id(): void
    {
        Mail::fake();
        $this->noCarrinho(105);
        $this->mock(MercadoPagoPaymentService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createPixPayment')->once()->andReturn([
                'id' => 77, 'status' => 'pending',
                'point_of_interaction' => ['transaction_data' => ['qr_code' => 'pix', 'qr_code_base64' => 'abc']],
            ]);
        });

        $this->postJson('/finalizacao/lead', $this->dados())->assertNoContent();
        $this->postJson('/finalizacao/entrega', [
            'shipping_method_id' => $this->frete->id,
            'guest' => ['name' => 'Maria Cliente Teste', 'email' => 'maria@exemplo.com', 'cpf' => '123.456.789-09', 'phone' => '(11) 99999-0000'],
            'new_address' => [
                'recipient_name' => 'Maria Cliente Teste', 'phone' => '(11) 99999-0000', 'zip' => '03187-040',
                'street' => 'Rua Mogi Mirim', 'number' => '20', 'neighborhood' => 'Mooca', 'city' => 'São Paulo', 'state' => 'SP',
            ],
        ])->assertOk();

        $this->assertSame(LeadCheckout::ETAPA_PAGAMENTO, LeadCheckout::sole()->etapa);

        $this->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true])->assertOk();

        $order = Order::sole();
        $lead = LeadCheckout::sole();
        $this->assertSame(LeadCheckout::STATUS_CONVERTIDO, $lead->status);
        $this->assertSame($order->id, $lead->order_id);
        $this->assertSame($order->user_id, $lead->user_id);
        $this->assertNotNull($lead->convertido_em);
    }

    public function test_pedido_pago_de_outra_sessao_converte_pelo_cpf(): void
    {
        $user = User::factory()->create(['email' => 'outro@exemplo.com', 'cpf' => '12345678909']);
        $lead = LeadCheckout::create([
            'site' => 'kazakora', 'session_id' => 'sessao-antiga', 'email' => 'maria@exemplo.com', 'cpf' => '12345678909',
            'itens' => [], 'status' => LeadCheckout::STATUS_ABERTO,
        ]);
        $outroSite = LeadCheckout::create([
            'site' => 'ferrakora', 'session_id' => 'x', 'cpf' => '12345678909', 'itens' => [], 'status' => LeadCheckout::STATUS_ABERTO,
        ]);
        $order = Order::create([
            'user_id' => $user->id, 'origin' => Order::ORIGIN_STORE, 'status' => Order::STATUS_AWAITING_PAYMENT,
            'shipping_name' => 'Maria', 'shipping_phone' => '11999990000', 'shipping_zip' => '01001-000',
            'shipping_street' => 'Praça da Sé', 'shipping_number' => '1', 'shipping_neighborhood' => 'Sé',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP', 'subtotal' => 100, 'total' => 100,
        ]);

        $this->assertSame(1, app(LeadsDoCheckout::class)->converter($order));

        $this->assertSame($order->id, $lead->fresh()->order_id);
        $this->assertSame(LeadCheckout::STATUS_ABERTO, $outroSite->fresh()->status);
    }

    public function test_admin_lista_os_carrinhos_abandonados_e_visitante_nao_ve(): void
    {
        LeadCheckout::create([
            'site' => 'kazakora', 'nome' => 'Maria Cliente Teste', 'email' => 'maria@exemplo.com', 'itens' => [],
            'status' => LeadCheckout::STATUS_ABERTO, 'subtotal' => 99.9,
        ]);

        $this->get('/admin/carrinhos-abandonados')->assertRedirect();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->get('/admin/carrinhos-abandonados')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/LeadsCheckout/Index', false)
                ->where('leads.data.0.email', 'maria@exemplo.com')
                ->where('resumo.abertos', 1)
                ->where('resumo.valor_aberto', 99.9));
    }
}
