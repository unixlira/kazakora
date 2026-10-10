<?php

namespace Tests\Feature\Checkout;

use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Política de Entrega (pedido 2026-10-10): Full e regiões vindas da configuração. */
class PoliticaEntregaTest extends TestCase
{
    public function test_pagina_mostra_regioes_e_horarios_do_full(): void
    {
        $this->get('/politica-de-entrega')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Legal/Entrega', false)
            ->where('full.horario_corte', '13h')
            ->where('full.faixas.0.local', 'São Paulo'));
    }
}
