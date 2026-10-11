<?php

namespace Tests\Feature\Loja;

use Tests\TestCase;

/** URL de exclusão de dados pedida pela Meta (pedido 2026-10-10). */
class ExclusaoDeDadosTest extends TestCase
{
    public function test_url_leva_para_a_secao_da_politica(): void
    {
        $this->get('/exclusao-de-dados')->assertRedirect('/politica-de-privacidade#exclusao-de-dados');
        $this->get('/politica-de-privacidade')->assertOk();
    }
}
