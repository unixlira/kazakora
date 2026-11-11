<?php

namespace Tests\Feature\FerraKora;

use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Lead;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Leads do checkout da FerraKora (pedido do Lira 11/11/2026): 18 pessoas da
 * campanha da parafusadeira abriram o checkout e saíram sem nada gravado.
 * Agora cada campo preenchido vira Lead (Admin > Leads, loja FerraKora) e o
 * pedido marca o lead como convertido.
 */
class LeadsCheckoutFerraKoraTest extends TestCase
{
    use RefreshDatabase;

    private Product $produto;

    protected function setUp(): void
    {
        parent::setUp();
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        Mail::fake();
        Bus::fake();

        $this->produto = Product::factory()->create([
            'sku' => 'FER-PAR-48V', 'name' => 'Parafusadeira 48V', 'price' => 150.00, 'stock' => 5, 'is_active' => true,
            'discount_percentage' => null, 'discount_amount' => null,
        ]);
        FerraKoraProduto::create(['product_id' => $this->produto->id, 'categoria' => 'Parafusadeiras', 'ordem' => 1, 'ativo' => true]);
    }

    private function itens(): array
    {
        return [['variante' => 'kzv-'.$this->produto->id, 'quantidade' => 1]];
    }

    public function test_cada_campo_atualiza_o_mesmo_lead_da_aba_com_a_origem_do_anuncio(): void
    {
        SiteVisit::query()->create([
            'site' => 'ferrakora', 'visitor_id' => substr(md5('127.0.0.1'), 0, 32), 'ip' => '127.0.0.1', 'path' => '/produto/kz-53',
            'origem' => 'meta_ads', 'utm_source' => 'facebook', 'utm_campaign' => 'parafusadeira-48v', 'consentiu' => false,
        ]);

        $this->postJson('/api/ferrakora/checkout/lead', ['itens' => $this->itens(), 'sessao' => 'aba123', 'campo' => 'cep', 'cep' => '01310-100'])->assertNoContent();
        $this->postJson('/api/ferrakora/checkout/lead', ['itens' => $this->itens(), 'sessao' => 'aba123', 'campo' => 'telefone', 'telefone' => '(11) 98888-7777'])->assertNoContent();

        $lead = Lead::query()->sole();
        $this->assertSame('ferrakora', $lead->site);
        $this->assertSame('01310100', $lead->cep);
        $this->assertSame('11988887777', $lead->telefone);
        $this->assertSame('telefone', $lead->ultimo_campo);
        $this->assertSame('Parafusadeira 48V', $lead->itens[0]['nome']);
        $this->assertSame(1, $lead->quantidade_itens);
        $this->assertSame('parafusadeira-48v', $lead->utm_campaign);
        $this->assertSame(Lead::STATUS_ABERTO, $lead->status);
    }

    public function test_dado_ruim_nunca_quebra_o_checkout(): void
    {
        $this->postJson('/api/ferrakora/checkout/lead', ['itens' => $this->itens(), 'sessao' => 'aba"; drop', 'cep' => '01310100'])->assertNoContent();
        $this->postJson('/api/ferrakora/checkout/lead', ['itens' => [['variante' => 'kzv-999999', 'quantidade' => 1]], 'sessao' => 'aba1', 'cep' => '01310100'])->assertNoContent();
        $this->postJson('/api/ferrakora/checkout/lead', ['sessao' => 'aba1', 'cep' => '01310100'])->assertNoContent();

        $this->assertSame(0, Lead::query()->count());
    }

    public function test_pedido_da_aba_converte_o_lead(): void
    {
        $this->postJson('/api/ferrakora/checkout/lead', ['itens' => $this->itens(), 'sessao' => 'aba123', 'campo' => 'email', 'email' => 'maria@exemplo.com'])->assertNoContent();

        $this->mock(MercadoPagoPaymentService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createPixPayment')->once()
                ->andReturn(['id' => 555, 'status' => 'pending', 'point_of_interaction' => ['transaction_data' => ['qr_code' => 'pix', 'qr_code_base64' => 'abc']]]);
        });

        $total = $this->postJson('/api/ferrakora/checkout/resumo', ['itens' => $this->itens(), 'cep' => '01310100'])->json('total_pix');
        $resposta = $this->postJson('/api/ferrakora/checkout/pedido', [
            'itens' => $this->itens(),
            'sessao' => 'aba123',
            'total_esperado' => $total,
            'cliente' => ['name' => 'Maria da Silva Souza', 'email' => 'maria@exemplo.com', 'cpf' => '123.456.789-09', 'phone' => '11988887777'],
            'endereco' => ['zip' => '01310-100', 'street' => 'Av. Paulista', 'number' => '1000', 'neighborhood' => 'Bela Vista', 'city' => 'São Paulo', 'state' => 'SP'],
            'pagamento' => ['metodo' => 'pix'],
        ])->assertCreated();

        $lead = Lead::query()->sole();
        $this->assertSame(Lead::STATUS_CONVERTIDO, $lead->status);
        $this->assertSame($resposta->json('pedido.id'), $lead->order_id);
    }
}
