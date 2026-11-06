<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Lead;
use App\Modules\Checkout\Models\LeadCheckout;
use App\Modules\Checkout\Models\Order;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Support\PaymentGateway;
use App\Support\Privacidade\Cookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Leads (pedido do Lira 2026-11-06): cada campo preenchido no checkout salva
 * por ajax (nome, e-mail, CPF, telefone, CEP) numa linha por sessão, com o
 * carrinho, e a tela Admin > Leads mostra.
 */
class LeadsDigitadosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // Navegador de verdade guarda o cookie da sessão; aqui o visitante faz esse papel.
        $this->withCredentials()->withCookie(Cookies::COOKIE_VISITANTE, 'visitante-teste-leads');
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
    }

    private function noCarrinho(float $preco = 105, int $quantidade = 1, array $extra = []): Product
    {
        $product = Product::factory()->create(['price' => $preco, 'stock' => 5, 'is_active' => true, ...$extra]);
        $this->post('/carrinho', ['product_id' => $product->id, 'quantity' => $quantidade]);

        return $product;
    }

    public function test_cada_campo_atualiza_a_mesma_linha_da_sessao(): void
    {
        $product = $this->noCarrinho(50, 2, ['variation' => 'Azul']);

        $this->postJson('/finalizacao/lead-campo', ['nome' => 'Maria', 'campo' => 'nome'])->assertNoContent();
        $this->assertSame('Maria', Lead::sole()->nome);

        $this->postJson('/finalizacao/lead-campo', ['nome' => 'Maria Teste', 'email' => 'Maria@Exem', 'campo' => 'email'])->assertNoContent();
        $this->postJson('/finalizacao/lead-campo', ['nome' => 'Maria Teste', 'email' => 'maria@exemplo.com', 'cpf' => '123.456.789-09', 'campo' => 'cpf'])->assertNoContent();
        $this->postJson('/finalizacao/lead-campo', [
            'nome' => 'Maria Teste', 'email' => 'maria@exemplo.com', 'cpf' => '123.456.789-09',
            'telefone' => '+55 (11) 99999-0000', 'cep' => '03187-040', 'campo' => 'cep',
        ])->assertNoContent();

        $lead = Lead::sole();
        $this->assertSame('Maria Teste', $lead->nome);
        $this->assertSame('maria@exemplo.com', $lead->email);
        $this->assertSame('12345678909', $lead->cpf);
        $this->assertSame('11999990000', $lead->telefone);
        $this->assertSame('03187040', $lead->cep);
        $this->assertSame('cep', $lead->ultimo_campo);
        $this->assertSame(Lead::STATUS_ABERTO, $lead->status);
        $this->assertSame(2, $lead->quantidade_itens);
        $this->assertEquals(100.0, (float) $lead->subtotal);
        $this->assertSame($product->id, $lead->itens[0]['product_id']);
        $this->assertSame('Azul', $lead->itens[0]['variacao']);
        // Não mexe na tela Carrinhos abandonados.
        $this->assertSame(0, LeadCheckout::count());
    }

    public function test_campo_apagado_nao_apaga_o_que_ja_se_sabia(): void
    {
        $this->noCarrinho();

        $this->postJson('/finalizacao/lead-campo', ['nome' => 'Maria Teste', 'telefone' => '11999990000'])->assertNoContent();
        $this->postJson('/finalizacao/lead-campo', ['nome' => 'Maria Teste', 'telefone' => ''])->assertNoContent();

        $this->assertSame('11999990000', Lead::sole()->telefone);
    }

    public function test_carrinho_vazio_ou_tudo_em_branco_nao_grava_e_nao_da_erro(): void
    {
        $this->postJson('/finalizacao/lead-campo', ['nome' => 'Maria Teste'])->assertNoContent();
        $this->assertSame(0, Lead::count());

        $this->noCarrinho();
        $this->postJson('/finalizacao/lead-campo', ['nome' => '  ', 'email' => ''])->assertNoContent();
        $this->assertSame(0, Lead::count());
    }

    public function test_pedido_criado_converte_o_lead(): void
    {
        Mail::fake();
        $frete = ShippingMethod::factory()->create(['price' => 0, 'is_active' => true]);
        $this->noCarrinho(105);
        $this->mock(MercadoPagoPaymentService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createPixPayment')->once()->andReturn([
                'id' => 78, 'status' => 'pending',
                'point_of_interaction' => ['transaction_data' => ['qr_code' => 'pix', 'qr_code_base64' => 'abc']],
            ]);
        });

        $this->postJson('/finalizacao/lead-campo', ['nome' => 'Maria Cliente Teste', 'email' => 'maria@exemplo.com', 'cep' => '03187040'])->assertNoContent();
        $this->postJson('/finalizacao/entrega', [
            'shipping_method_id' => $frete->id,
            'guest' => ['name' => 'Maria Cliente Teste', 'email' => 'maria@exemplo.com', 'cpf' => '123.456.789-09', 'phone' => '(11) 99999-0000'],
            'new_address' => [
                'recipient_name' => 'Maria Cliente Teste', 'phone' => '(11) 99999-0000', 'zip' => '03187-040',
                'street' => 'Rua Mogi Mirim', 'number' => '20', 'neighborhood' => 'Mooca', 'city' => 'São Paulo', 'state' => 'SP',
            ],
        ])->assertOk();
        $this->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true])->assertOk();

        $lead = Lead::sole();
        $this->assertSame(Lead::STATUS_CONVERTIDO, $lead->status);
        $this->assertSame(Order::sole()->id, $lead->order_id);
        $this->assertNotNull($lead->convertido_em);
    }

    public function test_limite_por_minuto(): void
    {
        $this->noCarrinho();
        for ($i = 0; $i < 60; $i++) {
            $this->postJson('/finalizacao/lead-campo', ['nome' => "Maria {$i}"])->assertNoContent();
        }
        $this->postJson('/finalizacao/lead-campo', ['nome' => 'Maria'])->assertStatus(429);
    }

    public function test_admin_ve_a_tela_leads_e_visitante_nao(): void
    {
        Lead::create([
            'site' => 'kazakora', 'nome' => 'Maria Cliente Teste', 'email' => 'maria@exemplo.com', 'cep' => '03187040',
            'itens' => [['product_id' => 1, 'nome' => 'Caixa', 'quantidade' => 1, 'preco_unitario' => 99.9, 'subtotal' => 99.9]],
            'status' => Lead::STATUS_ABERTO, 'subtotal' => 99.9,
        ]);

        $this->get('/admin/leads')->assertRedirect();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->get('/admin/leads')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Leads/Index', false)
                ->where('leads.data.0.email', 'maria@exemplo.com')
                ->where('leads.data.0.cep', '03187040')
                ->where('leads.data.0.agora', true)
                ->where('resumo.abertos', 1)
                ->where('resumo.agora', 1)
                ->where('resumo.valor_aberto', 99.9));
    }

    public function test_limpeza_apaga_lead_vencido(): void
    {
        $velho = Lead::create(['site' => 'kazakora', 'nome' => 'Velho', 'itens' => [], 'status' => Lead::STATUS_ABERTO]);
        $velho->forceFill(['updated_at' => now()->subDays(100)])->saveQuietly();
        $novo = Lead::create(['site' => 'kazakora', 'nome' => 'Novo', 'itens' => [], 'status' => Lead::STATUS_ABERTO]);

        $this->artisan('leads:limpar')->assertSuccessful();

        $this->assertNull($velho->fresh());
        $this->assertNotNull($novo->fresh());
    }
}
