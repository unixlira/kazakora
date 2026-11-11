<?php

namespace Tests\Feature\FerraKora;

use App\Modules\BeautyKora\Models\BeautyKoraProduto;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Support\PaymentGateway;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * URGENTE da FerraKora (pedido do Lira 2026-11-08): motoboy de R$ 39,00,
 * entrega em até 3 horas, na área e no horário do motoboy da KazaKora.
 */
class FreteUrgenteFerraKoraTest extends TestCase
{
    use RefreshDatabase;

    private Product $produto;

    protected function setUp(): void
    {
        parent::setUp();
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        Mail::fake();
        Bus::fake();
        config(['entrega_expressa.motoboy.ignorar_horario' => false, 'frete_embutido.transportadoras_melhor_envio' => []]);

        $this->produto = Product::factory()->create([
            'sku' => 'FER-TRE-5M', 'name' => 'Trena Emborrachada 5m', 'price' => 100.00, 'stock' => 5, 'is_active' => true,
            'discount_percentage' => null, 'discount_amount' => null,
        ]);
        FerraKoraProduto::create(['product_id' => $this->produto->id, 'categoria' => 'Trenas', 'ordem' => 1, 'ativo' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function itens(): array
    {
        return [['variante' => 'kzv-'.$this->produto->id, 'quantidade' => 2]];
    }

    private function urgente(array $resposta): ?array
    {
        return collect($resposta['entrega']['opcoes'])->firstWhere('id', 'urgente');
    }

    public function test_sao_paulo_em_horario_comercial_tem_urgente_de_39_reais_em_ate_3_horas(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 10:00', 'America/Sao_Paulo'));

        $resposta = $this->postJson('/api/ferrakora/checkout/resumo', ['itens' => $this->itens(), 'cep' => '01310100'])->assertOk()->json();

        $this->assertSame(3900, $this->urgente($resposta)['preco']);
        $this->assertSame('Entrega em até 3 horas', $this->urgente($resposta)['mensagem']);
        // A grátis continua escolhida; o URGENTE é só mais uma opção.
        $this->assertNotSame('urgente', $resposta['entrega']['escolhida']);
    }

    public function test_domingo_fora_do_horario_e_fora_da_area_nao_tem_urgente(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-08 10:00', 'America/Sao_Paulo'));
        $this->assertNull($this->urgente($this->postJson('/api/ferrakora/checkout/resumo', ['itens' => $this->itens(), 'cep' => '01310100'])->json()));

        Carbon::setTestNow(Carbon::parse('2026-11-09 19:00', 'America/Sao_Paulo'));
        $this->assertNull($this->urgente($this->postJson('/api/ferrakora/checkout/resumo', ['itens' => $this->itens(), 'cep' => '01310100'])->json()));

        Carbon::setTestNow(Carbon::parse('2026-11-09 10:00', 'America/Sao_Paulo'));
        $this->assertNull($this->urgente($this->postJson('/api/ferrakora/checkout/resumo', ['itens' => $this->itens(), 'cep' => '20040020'])->json()));
    }

    public function test_beauty_kora_nao_ganha_o_urgente(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 10:00', 'America/Sao_Paulo'));
        BeautyKoraProduto::create(['product_id' => $this->produto->id, 'categoria' => 'Teste', 'ordem' => 1, 'ativo' => true]);

        $resposta = $this->postJson('/api/beautykora/checkout/resumo', ['itens' => $this->itens(), 'cep' => '01310100'])->assertOk()->json();

        $this->assertNull($this->urgente($resposta));
    }

    public function test_urgente_escolhido_cobra_39_reais_e_grava_o_motoboy_no_pedido(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-09 10:00', 'America/Sao_Paulo'));
        $resumo = $this->postJson('/api/ferrakora/checkout/resumo', ['itens' => $this->itens(), 'cep' => '01310100', 'frete' => 'urgente'])->assertOk();
        $resumo->assertJsonPath('entrega.escolhida', 'urgente')->assertJsonPath('frete', 3900);

        $this->mock(MercadoPagoPaymentService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createPixPayment')->once()
                ->andReturn(['id' => 556, 'status' => 'pending', 'point_of_interaction' => ['transaction_data' => ['qr_code' => 'pix', 'qr_code_base64' => 'abc']]]);
        });

        $resposta = $this->postJson('/api/ferrakora/checkout/pedido', [
            'itens' => $this->itens(),
            'frete' => 'urgente',
            'total_esperado' => $resumo->json('total_pix'),
            'cliente' => ['name' => 'Maria da Silva Souza', 'email' => 'maria@exemplo.com', 'cpf' => '123.456.789-09', 'phone' => '11988887777'],
            'endereco' => ['zip' => '01310-100', 'street' => 'Av. Paulista', 'number' => '1000', 'neighborhood' => 'Bela Vista', 'city' => 'São Paulo', 'state' => 'sp'],
            'pagamento' => ['metodo' => 'pix'],
        ])->assertCreated();

        $order = Order::findOrFail($resposta->json('pedido.id'));
        $this->assertSame('39.00', (string) $order->shipping_cost);
        $this->assertSame('Motoboy URGENTE', $order->shipping_carrier_name);
    }
}
