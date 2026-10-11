<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Mail\PixAguardandoPagamento;
use App\Modules\Checkout\Models\Order;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Pix: Mercado Pago recebe e-mail nosso; a KazaKora manda o Pix ao cliente (pedido 2026-10-11). */
class PixEmailProprioTest extends TestCase
{
    use RefreshDatabase;

    public function test_mercado_pago_recebe_email_nosso_e_cliente_recebe_o_nosso(): void
    {
        Mail::fake();
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'email' => 'cliente@exemplo.com']);
        $product = Product::factory()->create(['price' => 100, 'stock' => 5, 'is_active' => true]);
        $metodo = ShippingMethod::query()->first() ?? ShippingMethod::factory()->create();
        $address = $user->addresses()->create(['label' => 'Casa', 'recipient_name' => 'Maria', 'phone' => '11999990000', 'zip' => '01001-000', 'street' => 'Praça da Sé', 'number' => '1', 'neighborhood' => 'Sé', 'city' => 'São Paulo', 'state' => 'SP']);

        $emailEnviadoAoMp = null;
        $this->mock(MercadoPagoPaymentService::class, function ($mock) use (&$emailEnviadoAoMp) {
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('createPixPayment')->once()->andReturnUsing(function ($valor, $payer) use (&$emailEnviadoAoMp) {
                $emailEnviadoAoMp = $payer['email'];

                return ['id' => 555, 'status' => 'pending', 'date_of_expiration' => now()->addMinutes(30)->toIso8601String(),
                    'point_of_interaction' => ['transaction_data' => ['qr_code' => '00020126PIXCOPIAECOLA', 'qr_code_base64' => base64_encode('png')]]];
            });
        });

        $this->actingAs($user)->post('/carrinho', ['product_id' => $product->id, 'quantity' => 1]);
        $this->actingAs($user)->withSession(['checkout_draft' => ['shipping_method_id' => $metodo->id, 'address_id' => $address->id]])
            ->post('/finalizacao/pagamento', ['payment_method' => 'pix', 'terms_accepted' => true]);

        $order = Order::firstOrFail();
        $this->assertSame("pedido-{$order->id}@kazakora.com", $emailEnviadoAoMp);
        Mail::assertSent(PixAguardandoPagamento::class, fn ($mail) => $mail->hasTo('cliente@exemplo.com')
            && $mail->copiaECola === '00020126PIXCOPIAECOLA' && $mail->order->is($order));
    }
}
