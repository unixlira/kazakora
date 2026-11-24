<?php

namespace Tests\Feature\WhatsApp;

use App\Models\User;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use App\Modules\WhatsApp\Services\WhatsAppModelos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tela de Conversas igual ao WhatsApp (pedido do Lira 23/11/2026):
 * mensagem de modelo vira balão (imagem, corpo formatado, rodapé, botões)
 * e a lista mostra o tique (✓, ✓✓, azul) da última mensagem nossa.
 * Graph falsa: WABA 900 = KazaKora (número 123).
 */
class InboxBalaoDeModeloEStatusTest extends TestCase
{
    use RefreshDatabase;

    private bool $graphForaDoAr = false;

    private int $buscasDeModelos = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.whatsapp.app_secret' => null,
            'services.whatsapp.access_token' => 'meta-token',
            'services.whatsapp.phone_number_id' => '123',
            'services.whatsapp.business_account_id' => '900',
            'services.whatsapp.manuela_url' => null,
            'services.gemini.api_key' => null,
            'services.whatsapp.graph_url' => 'https://graph.facebook.com/v20.0',
        ]);

        Http::fake(function (HttpRequest $request) {
            if (str_contains($request->url(), '/900/message_templates')) {
                $this->buscasDeModelos++;
                if ($this->graphForaDoAr) {
                    return Http::response(['error' => ['message' => 'fora do ar']], 500);
                }

                return Http::response(['data' => [[
                    'name' => 'carrinho_novidade', 'language' => 'pt_BR', 'status' => 'APPROVED', 'category' => 'MARKETING',
                    'components' => [
                        ['type' => 'HEADER', 'format' => 'IMAGE'],
                        ['type' => 'BODY', 'text' => "Oi *{{1}}*, o _{{2}}_ ficou no carrinho.\n{{3}}"],
                        ['type' => 'FOOTER', 'text' => 'KazaKora'],
                        ['type' => 'BUTTONS', 'buttons' => [
                            ['type' => 'URL', 'text' => 'Finalizar compra', 'url' => 'https://kazakora.com/carrinho/{{1}}'],
                            ['type' => 'QUICK_REPLY', 'text' => 'Falar com a Manuela'],
                            ['type' => 'PHONE_NUMBER', 'text' => 'Ligar', 'phone_number' => '+55 11 4000-0000'],
                        ]],
                    ],
                ]]]);
            }

            return Http::response(['messages' => [['id' => 'wamid.X']]]);
        });
    }

    private function conversa(): WhatsAppConversation
    {
        return WhatsAppConversation::paraNumero('5511999990000', 'kazakora', ['phone' => '5511999990000', 'ai_enabled' => false, 'unread_count' => 0]);
    }

    private function mensagem(WhatsAppConversation $conversa, array $dados): WhatsAppMessage
    {
        $mensagem = WhatsAppMessage::query()->create($dados + [
            'conversation_id' => $conversa->id,
            'direction' => 'outbound',
            'type' => 'text',
            'body' => 'Olá',
            'status' => 'sent',
            'sent_at' => now(),
        ]);
        $conversa->registerMessage($mensagem);

        return $mensagem;
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function mensagensDaTela(WhatsAppConversation $conversa): array
    {
        return $this->actingAs($this->admin())->getJson("/admin/whatsapp/conversas/{$conversa->id}/mensagens")->assertOk()->json('messages');
    }

    public function test_modelo_vira_balao_com_imagem_corpo_formatado_rodape_e_botoes(): void
    {
        $conversa = $this->conversa();
        $this->mensagem($conversa, [
            'type' => 'template',
            'body' => 'Carrinho abandonado (novidade 2): Jogo de panelas https://kazakora.com/carrinho/abc_1',
            'payload' => WhatsAppModelos::payloadDoEnvio('carrinho_novidade', 'pt_BR', ['Maria', 'Jogo de panelas', 'Chegou cor nova <b>já</b>'], 'https://kazakora.com/img/panela.jpg', [0 => 'abc_1']),
        ]);

        $balao = $this->mensagensDaTela($conversa)[0]['template_render'];

        $this->assertSame('image', $balao['cabecalho_tipo']);
        $this->assertSame('https://kazakora.com/img/panela.jpg', $balao['cabecalho_imagem']);
        // Variáveis preenchidas, *negrito* e _itálico_ do WhatsApp, quebra de linha mantida e HTML do cliente escapado.
        $this->assertSame("Oi <strong>Maria</strong>, o <em>Jogo de panelas</em> ficou no carrinho.\nChegou cor nova &lt;b&gt;já&lt;/b&gt;", $balao['corpo_formatado']);
        $this->assertSame('KazaKora', $balao['rodape']);
        $this->assertSame([
            ['texto' => 'Finalizar compra', 'tipo' => 'URL', 'url' => 'https://kazakora.com/carrinho/abc_1'],
            ['texto' => 'Falar com a Manuela', 'tipo' => 'QUICK_REPLY', 'url' => null],
            ['texto' => 'Ligar', 'tipo' => 'PHONE_NUMBER', 'url' => 'tel:+551140000000'],
        ], $balao['botoes']);
    }

    public function test_link_no_corpo_vira_link_sem_italico_no_sublinhado(): void
    {
        $this->assertSame(
            'Veja <a href="https://kazakora.com/a_b_c" target="_blank" rel="noopener noreferrer" class="wa-link">https://kazakora.com/a_b_c</a> e <s>antes</s>',
            WhatsAppModelos::formatar('Veja https://kazakora.com/a_b_c e ~antes~'),
        );
    }

    public function test_envio_pela_conversa_grava_no_payload_o_que_o_balao_precisa(): void
    {
        $conversa = $this->conversa();

        $resposta = $this->actingAs($this->admin())->postJson("/admin/whatsapp/conversas/{$conversa->id}/modelo", [
            'nome' => 'carrinho_novidade',
            'idioma' => 'pt_BR',
            'variaveis' => ['1' => 'Ana', '2' => 'Panela', '3' => 'Corre!'],
            'botoes' => [0 => 'xyz'],
            'cabecalho_link' => 'https://kazakora.com/img/p.jpg',
        ])->assertOk();

        $payload = WhatsAppMessage::query()->where('conversation_id', $conversa->id)->sole()->payload;
        $this->assertSame('carrinho_novidade', $payload['template']);
        $this->assertSame('pt_BR', $payload['idioma']);
        $this->assertSame('https://kazakora.com/img/p.jpg', $payload['cabecalho_link']);
        $this->assertSame('xyz', $payload['botoes'][0]);
        $this->assertSame('https://kazakora.com/carrinho/xyz', $resposta->json('message.template_render.botoes.0.url'));
        $this->assertStringContainsString('<strong>Ana</strong>', $resposta->json('message.template_render.corpo_formatado'));
    }

    public function test_mensagem_antiga_sem_variaveis_fica_no_texto_gravado(): void
    {
        $conversa = $this->conversa();
        $this->mensagem($conversa, [
            'type' => 'template',
            'body' => 'Carrinho abandonado (lembrete): Panela https://kazakora.com/c/1',
            'payload' => ['template' => 'carrinho_novidade', 'carrinho_sequencia_id' => 1, 'disparo' => 1],
        ]);

        $mensagem = $this->mensagensDaTela($conversa)[0];
        $this->assertNull($mensagem['template_render']);
        $this->assertSame('Carrinho abandonado (lembrete): Panela https://kazakora.com/c/1', $mensagem['body']);
        $this->assertSame(0, $this->buscasDeModelos);
    }

    public function test_graph_fora_do_ar_cai_no_texto_e_nao_insiste_a_cada_poll(): void
    {
        $this->graphForaDoAr = true;
        $conversa = $this->conversa();
        $this->mensagem($conversa, [
            'type' => 'template',
            'body' => 'Texto gravado',
            'payload' => WhatsAppModelos::payloadDoEnvio('carrinho_novidade', 'pt_BR', ['Maria', 'Panela', 'Oi']),
        ]);

        $this->assertNull($this->mensagensDaTela($conversa)[0]['template_render']);
        $this->assertNull($this->mensagensDaTela($conversa)[0]['template_render']);
        $this->assertSame(1, $this->buscasDeModelos);
    }

    public function test_lista_mostra_o_status_da_ultima_mensagem_quando_foi_nossa(): void
    {
        $lida = $this->conversa();
        $this->mensagem($lida, ['status' => 'read']);

        $entregue = WhatsAppConversation::paraNumero('5511988880000', 'kazakora', ['phone' => '5511988880000', 'unread_count' => 0]);
        $this->mensagem($entregue, ['status' => 'read']);
        $this->mensagem($entregue, ['status' => 'delivered']);

        $doCliente = WhatsAppConversation::paraNumero('5511977770000', 'kazakora', ['phone' => '5511977770000', 'unread_count' => 0]);
        $this->mensagem($doCliente, ['status' => 'read']);
        $this->mensagem($doCliente, ['direction' => 'inbound', 'status' => 'received', 'received_at' => now()]);

        $lista = collect($this->actingAs($this->admin())->getJson('/admin/whatsapp/conversas/atualizacoes')->assertOk()->json('conversations'))->keyBy('id');

        $this->assertSame('read', $lista[$lida->id]['previewStatus']);
        $this->assertSame('delivered', $lista[$entregue->id]['previewStatus']);
        $this->assertNull($lista[$doCliente->id]['previewStatus']);
    }

    public function test_status_do_webhook_atualiza_o_tique_da_lista_no_proximo_poll(): void
    {
        $conversa = $this->conversa();
        $this->mensagem($conversa, ['wa_message_id' => 'wamid.LISTA', 'status' => 'sent']);
        WhatsAppConversation::query()->whereKey($conversa->id)->update(['updated_at' => now()->subHour()]);

        $antes = now()->subSeconds(5)->toISOString();
        $this->postJson('/api/webhooks/whatsapp', ['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => '123'],
            'statuses' => [['id' => 'wamid.LISTA', 'status' => 'read', 'recipient_id' => '5511999990000']],
        ]]]]]])->assertOk();

        $lista = $this->actingAs($this->admin())->getJson('/admin/whatsapp/conversas/atualizacoes?since='.urlencode($antes))->assertOk()->json('conversations');

        $this->assertSame([$conversa->id], array_column($lista, 'id'));
        $this->assertSame('read', $lista[0]['previewStatus']);
    }
}
