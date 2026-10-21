<?php

namespace Tests\Feature\WhatsApp;

use App\Modules\WhatsApp\Services\ManuelaAutoReplyService;
use App\Modules\WhatsApp\Support\ManuelaSaudacao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Saudação da Manuela (pedido 2026-10-20): responde com a mesma saudação de
 * horário que o cliente usou; sem ela, "Oi" ou "Olá".
 */
class ManuelaSaudacaoTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconhece_a_saudacao_do_cliente(): void
    {
        $this->assertSame('Bom dia', ManuelaSaudacao::doCliente('Bom dia, tudo bem?'));
        $this->assertSame('Bom dia', ManuelaSaudacao::doCliente('bomdia'));
        $this->assertSame('Boa tarde', ManuelaSaudacao::doCliente('oi, boa tarde!'));
        $this->assertSame('Boa noite', ManuelaSaudacao::doCliente('BOA NOITE'));
        $this->assertNull(ManuelaSaudacao::doCliente('Oi, tem a caixa de ferramentas?'));
    }

    public function test_espelha_a_saudacao_no_lugar_do_oi(): void
    {
        $boasVindas = 'Oi, eu sou a Manuela da KazaKora.';

        $this->assertSame('Boa noite, eu sou a Manuela da KazaKora.', ManuelaSaudacao::espelhar($boasVindas, 'boa noite'));
        $this->assertSame('Bom dia, eu sou a Manuela da KazaKora.', ManuelaSaudacao::espelhar('Olá, eu sou a Manuela da KazaKora.', 'bom dia'));
        $this->assertSame($boasVindas, ManuelaSaudacao::espelhar($boasVindas, 'olá'));
        $this->assertSame('Boa tarde! Me conta o que procura.', ManuelaSaudacao::espelhar('Me conta o que procura.', 'boa tarde'));
    }

    public function test_regra_vai_no_prompt_da_manuela(): void
    {
        $this->assertStringContainsString(ManuelaSaudacao::REGRA, app(ManuelaAutoReplyService::class)->systemPrompt());
    }
}
