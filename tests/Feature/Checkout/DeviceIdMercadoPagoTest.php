<?php

namespace Tests\Feature\Checkout;

use App\Services\MercadoPago\MercadoPagoPaymentService;
use Tests\TestCase;

/** Device ID do comprador vai no X-meli-session-id (antifraude do Mercado Pago, 2026-11-24). */
class DeviceIdMercadoPagoTest extends TestCase
{
    public function test_aceita_device_id_valido_e_recusa_lixo(): void
    {
        request()->merge(['mp_device_id' => 'armor.abc123def456ghi789']);
        $this->assertSame('armor.abc123def456ghi789', MercadoPagoPaymentService::dispositivoDoComprador());

        request()->merge(['mp_device_id' => "<script>alert(1)</script>"]);
        $this->assertNull(MercadoPagoPaymentService::dispositivoDoComprador());

        request()->merge(['mp_device_id' => null]);
        $this->assertNull(MercadoPagoPaymentService::dispositivoDoComprador());
    }
}
