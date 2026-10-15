<?php

namespace Tests\Feature\FerraKora;

use App\Modules\Checkout\Mail\BoletoAguardandoPagamento;
use App\Modules\Checkout\Mail\OrderConfirmation;
use App\Modules\Checkout\Mail\PixAguardandoPagamento;
use App\Modules\Checkout\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * E-mails ao comprador com a marca do site (pedido do Lira 2026-10-15):
 * pedido do FerraKora sai com nome, logo e "Meus pedidos" do FerraKora.
 */
class EmailsFerraKoraTest extends TestCase
{
    use RefreshDatabase;

    private function pedido(?string $site): Order
    {
        return Order::create([
            'status' => Order::STATUS_AWAITING_PAYMENT, 'origin' => Order::ORIGIN_STORE, 'site_origem' => $site,
            'shipping_name' => 'Maria', 'shipping_email' => 'maria@exemplo.com', 'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000', 'shipping_street' => 'Rua X', 'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro', 'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 100, 'total' => 100,
        ]);
    }

    public function test_pedido_do_ferrakora_sai_com_a_marca_do_ferrakora(): void
    {
        $order = $this->pedido(Order::SITE_FERRAKORA);

        foreach ([
            new PixAguardandoPagamento($order, 100.0, 'pix-copia'),
            new BoletoAguardandoPagamento($order, 100.0, '2379', 'https://mp/boleto'),
            new OrderConfirmation($order),
        ] as $mail) {
            $html = $mail->render();
            $this->assertStringContainsString('/images/marca/ferrakora-email.png', $html);
            $this->assertStringContainsString('alt="FerraKora"', $html);
            $this->assertStringContainsString('https://ferrakora.devlira.com.br/meus-pedidos', $html);
            $this->assertStringNotContainsString(route('pedidos.meus'), $html);
            $this->assertTrue($mail->hasFrom(config('mail.from.address'), 'FerraKora'));
        }

        $this->assertStringEndsWith('- FerraKora', (new OrderConfirmation($order))->build()->subject);
    }

    public function test_pedido_da_kazakora_continua_igual(): void
    {
        $order = $this->pedido(null);
        $mail = new PixAguardandoPagamento($order, 100.0, 'pix-copia');
        $html = $mail->render();

        $this->assertStringContainsString('alt="KazaKora"', $html);
        $this->assertStringContainsString(route('pedidos.meus'), $html);
        $this->assertStringNotContainsString('ferrakora', $html);
        $this->assertFalse($mail->hasFrom(config('mail.from.address'), 'FerraKora'));
        $this->assertStringEndsWith('- KazaKora', (new OrderConfirmation($order))->build()->subject);
    }
}
