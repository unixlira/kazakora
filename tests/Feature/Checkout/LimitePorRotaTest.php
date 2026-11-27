<?php

namespace Tests\Feature\Checkout;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Incidente de 27/11/2026 (cliente Bernardete): o salvamento de cada campo
 * do checkout gastava o limite do "Finalizar" e o pagamento voltava 429.
 * Cada `throttle:N,M` tem que contar só as batidas da própria rota.
 */
class LimitePorRotaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('throttle:60,1')->post('/_teste/campo', fn () => response()->noContent())->name('teste.campo');
        Route::middleware('throttle:10,1')->post('/_teste/pagar', fn () => response('ok'))->name('teste.pagar');
    }

    public function test_batidas_de_outra_rota_nao_gastam_o_limite_do_pagamento(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->post('/_teste/campo')->assertNoContent();
        }

        $this->post('/_teste/pagar')->assertOk();
    }

    public function test_o_limite_da_propria_rota_continua_valendo(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post('/_teste/pagar')->assertOk();
        }

        $this->post('/_teste/pagar')->assertStatus(429);
    }
}
