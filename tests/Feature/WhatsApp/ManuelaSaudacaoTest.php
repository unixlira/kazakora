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
        $prompt = app(ManuelaAutoReplyService::class)->systemPrompt();

        $this->assertStringContainsString(ManuelaSaudacao::regra('KazaKora'), $prompt);
        $this->assertStringContainsString('Eu sou a Manuela do KazaKora. Como posso te ajudar hoje?', $prompt);
    }

    public function test_apresentacao_usa_o_nome_da_loja(): void
    {
        $this->assertSame('Eu sou a Manuela do FerraKora. Como posso te ajudar hoje?', ManuelaSaudacao::apresentacao('FerraKora'));
        $this->assertStringContainsString('"Eu sou a Manuela do FerraKora. Como posso te ajudar hoje?"', ManuelaSaudacao::regra('FerraKora'));
    }

    public function test_tira_o_tudo_bem_que_o_cliente_nao_perguntou(): void
    {
        $this->assertSame(
            'Boa tarde! Eu sou a Manuela do KazaKora. Como posso te ajudar hoje?',
            ManuelaSaudacao::semTudoBem('Boa tarde, tudo bem com você? Eu sou a Manuela do KazaKora. Como posso te ajudar hoje?', 'boa tarde'),
        );
        $this->assertSame(
            'Bom dia! Eu sou a Manuela do KazaKora. Como posso te ajudar hoje?',
            ManuelaSaudacao::semTudoBem('Bom dia! Tudo bem? 😊 Eu sou a Manuela do KazaKora. Como posso te ajudar hoje?', 'bom dia'),
        );
        $this->assertSame('Oi, Maria! Eu sou a Manuela do KazaKora.', ManuelaSaudacao::semTudoBem('Oi, Maria, tudo bem? Eu sou a Manuela do KazaKora.', 'oi'));
        // "Tudo bem" sem pergunta não é cumprimento: fica.
        $this->assertSame('Tudo bem, vou verificar com o time.', ManuelaSaudacao::semTudoBem('Tudo bem, vou verificar com o time.', 'pode ver pra mim?'));
    }

    public function test_tudo_bem_fica_quando_o_cliente_perguntou_primeiro(): void
    {
        $resposta = 'Boa noite! Tudo ótimo por aqui, e com você? Eu sou a Manuela do KazaKora. Como posso te ajudar hoje?';

        $this->assertSame($resposta, ManuelaSaudacao::semTudoBem($resposta, 'Boa noite, tudo bem?'));
        $this->assertSame('Oi! Tudo bem? Posso ajudar?', ManuelaSaudacao::semTudoBem('Oi! Tudo bem? Posso ajudar?', 'oi td bem'));
        $this->assertTrue(ManuelaSaudacao::clientePerguntouComoVai('Olá, como vai?'));
        $this->assertFalse(ManuelaSaudacao::clientePerguntouComoVai('Boa tarde, tem a caixa de ferramentas?'));
    }
}
