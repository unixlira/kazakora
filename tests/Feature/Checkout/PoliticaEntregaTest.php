<?php

namespace Tests\Feature\Checkout;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Política de Entrega (pedido 2026-10-10): Entrega Rápida (ex-Full, nome de 2026-11-24) e regiões vindas da configuração. */
class PoliticaEntregaTest extends TestCase
{
    use RefreshDatabase;

    public function test_pagina_mostra_regioes_e_horarios_da_entrega_rapida(): void
    {
        $this->get('/politica-de-entrega')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Legal/Entrega', false)
            ->where('rapida.horario_corte', '13h')
            ->where('rapida.faixas.0.local', 'São Paulo')
            ->where('motoboy.preco', fn ($preco) => (float) $preco === 39.0));
    }
}
