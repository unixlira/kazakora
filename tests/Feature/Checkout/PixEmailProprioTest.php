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

    /** QR Code no meio do e-mail como anexo inline (CID), que o Gmail mostra (pedido 2026-10-16). */
    public function test_qr_code_vai_inline_no_email_mesmo_sem_o_png_do_mercado_pago(): void
    {
        config(['mail.default' => 'array']);
        $order = new Order(['numero' => '261016TESTE123']);
        $order->id = 1;
        $copiaECola = '00020126580014br.gov.bcb.pix0136123e4567-e12b-12d1-a456-4266554400005204000053039865406099.905802BR5908KAZAKORA6009SAO PAULO62070503***6304ABCD';

        $mail = new PixAguardandoPagamento($order, 99.90, $copiaECola, null, now()->addMinutes(30)->toIso8601String());
        $png = $mail->qrPng();
        $this->assertNotNull($png);
        $this->assertStringStartsWith("\x89PNG", $png);

        Mail::to('cliente@exemplo.com')->send($mail);
        $enviado = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $inline = collect($enviado->getAttachments())->filter(fn ($a) => $a->getDisposition() === 'inline' && $a->getMediaType() === 'image');
        $this->assertCount(1, $inline);
        $html = $enviado->getHtmlBody();
        $this->assertStringContainsString('src="cid:', $html);
        $this->assertStringNotContainsString('data:image', $html);
        // Fica entre o valor a pagar e o copia e cola.
        $this->assertTrue(strpos($html, 'Valor a pagar') < strpos($html, 'cid:') && strpos($html, 'cid:') < strpos($html, '>Pix copia e cola<'));
    }

    public function test_usa_o_png_do_mercado_pago_quando_vem_valido_e_some_sem_copia_e_cola(): void
    {
        $order = new Order(['numero' => '261016TESTE123']);
        $pngDoMp = "\x89PNG\r\n\x1a\nqualquer";

        $this->assertSame($pngDoMp, (new PixAguardandoPagamento($order, 10, '000201X', base64_encode($pngDoMp)))->qrPng());
        $this->assertNull((new PixAguardandoPagamento($order, 10, '', null))->qrPng());
    }
}
