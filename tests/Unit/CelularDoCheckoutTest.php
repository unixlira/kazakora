<?php

namespace Tests\Unit;

use App\Modules\Checkout\Support\CelularDoCheckout;
use App\Modules\Checkout\Support\LeadsDoCheckout;
use App\Modules\WhatsApp\Support\WhatsAppPhone;
use PHPUnit\Framework\TestCase;

/**
 * Pedido 4562 (26/11/2026): "+55 11 99123-4567" virava "(55) 11991-2345"
 * (o 55 do país como DDD e o número cortado) e o WhatsApp do pedido não saía.
 */
class CelularDoCheckoutTest extends TestCase
{
    public function test_55_do_pais_sai_e_nunca_corta(): void
    {
        $this->assertSame('(11) 99123-4567', CelularDoCheckout::formatar('5511991234567'));
        $this->assertSame('(11) 99123-4567', CelularDoCheckout::formatar('+55 (11) 99123-4567'));
        $this->assertSame('(11) 99123-4567', CelularDoCheckout::formatar('011991234567'));
        $this->assertSame('(11) 3265-4321', CelularDoCheckout::formatar('551132654321'));
        // DDD 55 de verdade (RS) continua.
        $this->assertSame('(55) 99999-8888', CelularDoCheckout::formatar('55999998888'));
    }

    public function test_numero_cortado_ou_ddd_inexistente_da_erro(): void
    {
        $this->assertNotNull(CelularDoCheckout::erro('(55) 11991-2345'));
        $this->assertNotNull(CelularDoCheckout::erro('(20) 98765-4321'));
        $this->assertNotNull(CelularDoCheckout::erro('1698262'));
        $this->assertNull(CelularDoCheckout::erro('(11) 99123-4567'));
        $this->assertNull(LeadsDoCheckout::telefone('(55) 11991-2345'));
        $this->assertSame('11991234567', LeadsDoCheckout::telefone('5511991234567'));
    }

    public function test_whatsapp_normaliza_com_ddd_real(): void
    {
        $this->assertSame('5511991234567', WhatsAppPhone::normalize('(11) 99123-4567'));
        $this->assertSame('5511991234567', WhatsAppPhone::normalize('5511991234567'));
        $this->assertSame('5555999998888', WhatsAppPhone::normalize('(55) 99999-8888'));
        $this->assertSame('558592466924', WhatsAppPhone::normalize('558592466924'));
        $this->assertNull(WhatsAppPhone::normalize('(55) 11991-2345'));
        $this->assertNull(WhatsAppPhone::normalize('55119912345'));
        $this->assertNull(WhatsAppPhone::normalize('(20) 98765-4321'));
        $this->assertTrue(WhatsAppPhone::mesmoNumero('5511991234567', ['(11) 99123-4567']));
    }
}
