<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Número do pedido da loja no formato da Shopee (pedido 2026-10-11). */
class NumeroDoPedidoTest extends TestCase
{
    use RefreshDatabase;

    public function test_formato_data_mais_8_caracteres_sem_letras_confusas(): void
    {
        $numero = Order::gerarNumeroDaLoja();

        $this->assertMatchesRegularExpression('/^\d{6}[0-9A-HJKMNP-TV-Z]{8}$/', $numero);
        $this->assertStringStartsWith(now('America/Sao_Paulo')->format('ymd'), $numero);
    }

    public function test_rastreio_acha_pelo_numero_novo(): void
    {
        $user = User::factory()->create(['email' => 'maria@exemplo.com']);
        $order = Order::create([
            'user_id' => $user->id, 'status' => Order::STATUS_PAID, 'external_order_id' => '261011K7Q2M9XD',
            'shipping_name' => 'Maria', 'shipping_phone' => '11999990000', 'shipping_zip' => '03187040',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Mooca',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP', 'subtotal' => 100, 'shipping_cost' => 0, 'total' => 100,
        ]);

        $this->assertSame('261011K7Q2M9XD', $order->numero);
        $this->post('/rastreio', ['pedido' => '261011k7q2m9xd', 'documento' => 'maria@exemplo.com'])
            ->assertRedirect(route('rastreio.ver', $order->trackingRef()));
    }
}
