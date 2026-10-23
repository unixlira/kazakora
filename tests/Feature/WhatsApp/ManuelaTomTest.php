<?php

namespace Tests\Feature\WhatsApp;

use App\Modules\Suporte\Models\Chamado;
use App\Modules\Suporte\Services\ManuelaSuporte;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Services\ManuelaAutoReplyService;
use App\Modules\WhatsApp\Support\WhatsAppNumeros;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ManuelaTomTest extends TestCase
{
    use RefreshDatabase;

    public function test_prompt_do_whatsapp_kazakora_tem_tom_alegre_e_serio_na_reclamacao(): void
    {
        $prompt = app(ManuelaAutoReplyService::class)->systemPrompt();

        $this->assertStringContainsString('simpática e alegre', $prompt);
        $this->assertStringContainsString('séria e cordial', $prompt);
        $this->assertStringContainsString('palavrão', $prompt);
        $this->assertStringContainsString('nunca ofereça mandar áudio', $prompt);
    }

    public function test_prompt_da_ferrakora_tem_a_mesma_regra_de_tom(): void
    {
        $conversa = new WhatsAppConversation(['loja' => WhatsAppNumeros::FERRAKORA]);

        $prompt = app(ManuelaAutoReplyService::class)->systemPrompt($conversa);

        $this->assertStringContainsString('séria e cordial', $prompt);
    }

    public function test_chat_do_site_tem_a_mesma_regra_de_tom(): void
    {
        $metodo = new \ReflectionMethod(ManuelaSuporte::class, 'instrucoes');

        $prompt = $metodo->invoke(app(ManuelaSuporte::class), new Chamado);

        $this->assertStringContainsString('séria e cordial', $prompt);
        $this->assertStringContainsString('palavrão', $prompt);
    }
}
