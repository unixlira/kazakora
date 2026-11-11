<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Modelo aprovado direto da conversa (pedido do Lira 11/11/2026): fora da
 * janela de 24h a tela lista os modelos aprovados da WABA do número da
 * conversa, o atendente escolhe, preenche as variáveis e manda dali.
 * Graph falsa: WABA 900 = KazaKora (número 123), 901 = FerraKora (456).
 */
class ModeloNaConversaTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $enviados = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.app_secret' => null,
            'services.whatsapp.access_token' => 'meta-token',
            'services.whatsapp.phone_number_id' => '123',
            'services.whatsapp.business_account_id' => '900',
            'services.whatsapp.numeros.ferrakora.phone_number_id' => '456',
            'services.whatsapp.numeros.ferrakora.business_account_id' => '901',
            'services.whatsapp.numeros.ferrakora.access_token' => 'ferra-token',
            'services.whatsapp.graph_url' => 'https://graph.facebook.com/v20.0',
        ]);

        Http::fake(function (HttpRequest $request) {
            $url = $request->url();

            if (str_contains($url, '/900/message_templates')) {
                return Http::response(['data' => [
                    [
                        'name' => 'retomar_conversa', 'language' => 'pt_BR', 'status' => 'APPROVED', 'category' => 'UTILITY',
                        'components' => [
                            ['type' => 'BODY', 'text' => 'Oi {{1}}, sobre o pedido #{{2}}: podemos continuar por aqui?'],
                            ['type' => 'FOOTER', 'text' => 'KazaKora'],
                            ['type' => 'BUTTONS', 'buttons' => [
                                ['type' => 'URL', 'text' => 'Ver pedido', 'url' => 'https://kazakora.com/pedidos/{{1}}'],
                                ['type' => 'QUICK_REPLY', 'text' => 'Falar com atendente'],
                            ]],
                        ],
                    ],
                    [
                        'name' => 'oferta_nomeada', 'language' => 'pt_BR', 'status' => 'APPROVED', 'category' => 'MARKETING', 'parameter_format' => 'NAMED',
                        'components' => [['type' => 'BODY', 'text' => 'Oi {{nome}}, chegou novidade!']],
                    ],
                    [
                        'name' => 'cabecalho_variavel', 'language' => 'pt_BR', 'status' => 'APPROVED', 'category' => 'MARKETING',
                        'components' => [['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Oi {{1}}'], ['type' => 'BODY', 'text' => 'Texto']],
                    ],
                ]]);
            }

            if (str_contains($url, '/901/message_templates')) {
                return Http::response(['data' => [[
                    'name' => 'ferrakora_ola', 'language' => 'pt_BR', 'status' => 'APPROVED', 'category' => 'UTILITY',
                    'components' => [['type' => 'BODY', 'text' => 'Oi! Aqui é a FerraKora.']],
                ]]]);
            }

            if (str_ends_with($url, '/messages')) {
                $this->enviados[] = ['url' => $url, 'token' => $request->header('Authorization')[0] ?? null, 'dados' => $request->data()];

                return Http::response(['messages' => [['id' => 'wamid.MODELO'.count($this->enviados)]]]);
            }

            return Http::response(['error' => ['message' => 'inesperado '.$url]], 400);
        });
    }

    private function conversa(string $loja = 'kazakora'): WhatsAppConversation
    {
        return WhatsAppConversation::query()->create([
            'wa_id' => '5511999990000',
            'phone' => '5511999990000',
            'loja' => $loja,
            'ai_enabled' => true,
            'last_customer_message_at' => now()->subDays(3),
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_lista_os_modelos_aprovados_do_numero_da_conversa(): void
    {
        $conversa = $this->conversa();

        $modelos = $this->actingAs($this->admin())->getJson("/admin/whatsapp/conversas/{$conversa->id}/modelos")
            ->assertOk()
            ->assertJsonPath('loja', 'KazaKora')
            ->json('modelos');

        $this->assertSame(['cabecalho_variavel', 'oferta_nomeada', 'retomar_conversa'], array_column($modelos, 'nome'));
        $retomar = $modelos[2];
        $this->assertSame(['1', '2'], $retomar['variaveis']);
        $this->assertTrue($retomar['botoes'][0]['variavel']);
        $this->assertFalse($retomar['botoes'][1]['variavel']);
        $this->assertTrue($retomar['suportado']);
        $this->assertFalse($modelos[0]['suportado']);

        // FerraKora lê a WABA dela, com o token dela.
        $this->actingAs($this->admin())->getJson('/admin/whatsapp/conversas/'.$this->conversa('ferrakora')->id.'/modelos')
            ->assertOk()
            ->assertJsonPath('modelos.0.nome', 'ferrakora_ola');
    }

    public function test_manda_o_modelo_preenchido_fora_da_janela_e_grava_no_historico(): void
    {
        $conversa = $this->conversa();
        $this->assertFalse($conversa->insideServiceWindow());

        $this->actingAs($this->admin())->postJson("/admin/whatsapp/conversas/{$conversa->id}/modelo", [
            'nome' => 'retomar_conversa',
            'idioma' => 'pt_BR',
            'variaveis' => ['1' => 'Maria', '2' => '4059'],
            'botoes' => [0 => '4059'],
        ])->assertOk()
            ->assertJsonPath('message.type', 'template')
            ->assertJsonPath('message.status', 'sent');

        $this->assertCount(1, $this->enviados);
        $enviado = $this->enviados[0];
        $this->assertStringContainsString('/123/messages', $enviado['url']);
        $this->assertSame('retomar_conversa', $enviado['dados']['template']['name']);
        $this->assertSame([['type' => 'text', 'text' => 'Maria'], ['type' => 'text', 'text' => '4059']], $enviado['dados']['template']['components'][0]['parameters']);
        $this->assertSame('url', $enviado['dados']['template']['components'][1]['sub_type']);
        $this->assertSame('4059', $enviado['dados']['template']['components'][1]['parameters'][0]['text']);

        $mensagem = WhatsAppMessage::query()->where('conversation_id', $conversa->id)->sole();
        $this->assertSame('wamid.MODELO1', $mensagem->wa_message_id);
        $this->assertStringContainsString('Oi Maria, sobre o pedido #4059', $mensagem->body);
        $this->assertStringContainsString('[Ver pedido]', $mensagem->body);
        // Atendente mandou: a Manuela sai da conversa.
        $this->assertFalse($conversa->fresh()->ai_enabled);
    }

    public function test_variavel_nomeada_vai_com_o_nome(): void
    {
        $conversa = $this->conversa();

        $this->actingAs($this->admin())->postJson("/admin/whatsapp/conversas/{$conversa->id}/modelo", [
            'nome' => 'oferta_nomeada', 'idioma' => 'pt_BR', 'variaveis' => ['nome' => 'João'],
        ])->assertOk();

        $this->assertSame(
            [['type' => 'text', 'parameter_name' => 'nome', 'text' => 'João']],
            $this->enviados[0]['dados']['template']['components'][0]['parameters'],
        );
    }

    public function test_recusa_campo_vazio_modelo_desconhecido_e_modelo_nao_suportado(): void
    {
        $conversa = $this->conversa();
        $admin = $this->admin();

        $this->actingAs($admin)->postJson("/admin/whatsapp/conversas/{$conversa->id}/modelo", [
            'nome' => 'retomar_conversa', 'idioma' => 'pt_BR', 'variaveis' => ['1' => 'Maria'], 'botoes' => [0 => '1'],
        ])->assertStatus(422)->assertJsonPath('message', 'Preencha a variável {{2}} do modelo.');

        $this->actingAs($admin)->postJson("/admin/whatsapp/conversas/{$conversa->id}/modelo", [
            'nome' => 'nao_existe', 'idioma' => 'pt_BR',
        ])->assertStatus(422);

        $this->actingAs($admin)->postJson("/admin/whatsapp/conversas/{$conversa->id}/modelo", [
            'nome' => 'cabecalho_variavel', 'idioma' => 'pt_BR',
        ])->assertStatus(422);

        // Modelo da KazaKora não sai pela conversa da FerraKora.
        $this->actingAs($admin)->postJson('/admin/whatsapp/conversas/'.$this->conversa('ferrakora')->id.'/modelo', [
            'nome' => 'retomar_conversa', 'idioma' => 'pt_BR', 'variaveis' => ['1' => 'a', '2' => 'b'], 'botoes' => [0 => '1'],
        ])->assertStatus(422);

        $this->assertSame([], $this->enviados);
    }
}
