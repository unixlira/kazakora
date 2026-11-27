<?php

namespace Tests\Feature\Checkout;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Repetição segura do "Finalizar" (pedido do Lira em 27/11/2026, cliente
 * Bernardete): a tela repete sozinha quando a rede ou o servidor falham, com
 * a mesma chave de tentativa. A repetição nunca cria outro pedido nem outra
 * cobrança, e a desistência avisa o Lira.
 */
class PagamentoRepeticaoSeguraTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        $this->withoutVite();
        $frete = ShippingMethod::factory()->create(['price' => 0, 'is_active' => true]);
        $produto = Product::factory()->create(['price' => 100, 'stock' => 5, 'is_active' => true, 'checkout_discount_percentage' => 0, 'discount_percentage' => null, 'discount_amount' => null]);
        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 1]);
        $this->get('/finalizacao');
        $this->postJson('/finalizacao/entrega', [
            'shipping_method_id' => $frete->id,
            'guest' => ['name' => 'Bernardete Cliente', 'email' => 'bernardete@exemplo.com', 'cpf' => '123.456.789-09', 'phone' => '(48) 99968-0000'],
            'new_address' => [
                'recipient_name' => 'Bernardete Cliente', 'phone' => '(48) 99968-0000', 'zip' => '88020-270',
                'street' => 'Rua X', 'number' => '20', 'neighborhood' => 'Centro', 'city' => 'Florianópolis', 'state' => 'SC',
            ],
        ])->assertOk();
    }

    public function test_mesma_tentativa_repetida_nao_cria_outro_pedido_nem_outro_pix(): void
    {
        $this->mock(MercadoPagoPaymentService::class, function ($mock) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            // Uma cobrança só, mesmo com o "Finalizar" chegando duas vezes.
            $mock->shouldReceive('createPixPayment')->once()->andReturn([
                'id' => 77, 'status' => 'pending',
                'point_of_interaction' => ['transaction_data' => ['qr_code' => 'pix-77', 'qr_code_base64' => 'abc']],
            ]);
        });
        $pagamento = ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true, 'tentativa' => '9f1c2a7e-0d4b-4c1e-9a55-3b7d2e8f6a10'];

        $this->post('/finalizacao/pagamento', $pagamento)->assertOk()
            ->assertInertia(fn ($page) => $page->where('mercadoPagoPix.qrCode', 'pix-77'));
        // Repetição com a mesma chave (a sessão do convidado já foi renovada).
        $this->post('/finalizacao/pagamento', $pagamento)->assertOk()
            ->assertInertia(fn ($page) => $page->where('mercadoPagoPix.qrCode', 'pix-77'));

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_tentativa_ainda_rodando_responde_409_para_a_tela_esperar(): void
    {
        $tentativa = '5b2e9c41-7a3d-4f08-b6e1-2c9d8a7f3e55';
        Cache::lock('checkout:tentativa:'.sha1($tentativa).':trava', 120)->get();

        $this->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'split' => false, 'terms_accepted' => true, 'tentativa' => $tentativa])
            ->assertStatus(409);
        $this->assertSame(0, Order::query()->count());
    }

    public function test_desistencia_avisa_o_lira(): void
    {
        Log::spy();

        $this->postJson('/finalizacao/pagamento-falhou', ['status' => 503, 'tentativas' => 6, 'metodo' => 'card', 'etapa' => 'pagamento'])
            ->assertOk()->assertJson(['ok' => true]);

        Log::shouldHaveReceived('error')->withArgs(fn ($mensagem, $dados) => $mensagem === 'checkout.pagamento_bloqueado'
            && $dados['status'] === 503 && $dados['cliente'] === 'Bernardete Cliente' && (float) $dados['total'] === 100.0);
    }
}
