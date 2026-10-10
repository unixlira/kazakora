<?php

namespace Tests\Feature\Checkout;

use App\Services\MercadoPago\CredenciaisCheckout;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Modo teste do Mercado Pago só no checkout (pedido 2026-10-10). */
class MercadoPagoModoTesteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mercadopago.access_token' => 'APP_USR-real',
            'services.mercadopago.public_key' => 'APP_USR-pk-real',
            'services.mercadopago.teste_access_token' => 'TEST-token',
            'services.mercadopago.teste_public_key' => 'TEST-pk',
        ]);
    }

    public function test_desligado_usa_as_credenciais_reais(): void
    {
        config(['services.mercadopago.modo_teste' => false]);

        $this->assertFalse(CredenciaisCheckout::emTeste());
        $this->assertSame('APP_USR-real', CredenciaisCheckout::accessToken());
        $this->assertSame('APP_USR-pk-real', CredenciaisCheckout::publicKey());
    }

    public function test_ligado_usa_as_de_teste_no_pagamento(): void
    {
        config(['services.mercadopago.modo_teste' => true]);
        Http::fake(['*' => Http::response(['id' => 'ORD1', 'status' => 'processed'])]);

        app(MercadoPagoPaymentService::class)->createOrder(['total_amount' => '10.00'], 'chave-1');

        $this->assertSame('TEST-pk', CredenciaisCheckout::publicKey());
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer TEST-token'));
    }

    public function test_ligado_sem_as_duas_chaves_de_teste_segue_com_as_reais(): void
    {
        config(['services.mercadopago.modo_teste' => true, 'services.mercadopago.teste_public_key' => null]);

        $this->assertFalse(CredenciaisCheckout::emTeste());
        $this->assertSame('APP_USR-real', CredenciaisCheckout::accessToken());
    }
}
