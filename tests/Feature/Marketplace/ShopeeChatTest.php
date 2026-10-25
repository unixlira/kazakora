<?php

namespace Tests\Feature\Marketplace;

use App\Models\User;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ShopeeChatConversa;
use App\Modules\Marketplace\Models\ShopeeChatMensagem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopeeChatTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENTE = 5259128304;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.shopee.api_base_url' => 'https://shopee.test', 'services.shopee.partner_id' => 1, 'services.shopee.partner_key' => 'k']);

        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_SHOPEE,
            'status' => MarketplaceAccount::STATUS_CONNECTED,
            'seller_id' => '123',
            'access_token' => 'fake',
            'refresh_token' => 'fake',
            'token_expires_at' => now()->addHours(4),
            'connected_at' => now(),
        ]);
    }

    private function conversa(string $id = 'c1', int $naoLidas = 2, string $ultima = 'm2'): array
    {
        return [
            'conversation_id' => $id, 'to_id' => self::CLIENTE, 'to_name' => 'maria123', 'to_avatar' => '',
            'unread_count' => $naoLidas, 'latest_message_id' => $ultima, 'latest_message_type' => 'text',
            'latest_message_content' => ['text' => 'Cadê meu pedido?'], 'latest_message_from_id' => self::CLIENTE,
            'last_message_timestamp' => 1761342060,
        ];
    }

    private function mensagem(string $id, int $de, string $texto, int $ts): array
    {
        return ['message_id' => $id, 'from_id' => $de, 'to_id' => 1, 'message_type' => 'text', 'content' => ['text' => $texto], 'conversation_id' => 'c1', 'created_timestamp' => $ts, 'source' => 'ios'];
    }

    private function fakeShopee(array $naoLidas, array $todas, array $mensagens = []): void
    {
        Http::fake([
            '*get_conversation_list*type=unread*' => Http::response(['error' => '', 'response' => ['conversations' => $naoLidas, 'page_result' => ['more' => false]]]),
            '*get_conversation_list*type=all*' => Http::response(['error' => '', 'response' => ['conversations' => $todas, 'page_result' => ['more' => false]]]),
            '*get_message*' => Http::response(['error' => '', 'response' => ['messages' => $mensagens, 'page_result' => ['page_size' => 50]]]),
        ]);
    }

    public function test_sync_grava_conversa_nao_lida_e_mensagens(): void
    {
        $this->fakeShopee([$this->conversa()], [$this->conversa()], [
            $this->mensagem('m2', self::CLIENTE, 'Cadê meu pedido?', 1761342060),
            $this->mensagem('m1', 1, 'Olá, em que posso ajudar?', 1761342000),
        ]);

        $this->artisan('shopee:chat-sync')->assertSuccessful();

        $c = ShopeeChatConversa::where('conversation_id', 'c1')->firstOrFail();
        $this->assertSame(2, $c->nao_lidas);
        $this->assertSame('maria123', $c->comprador_nome);
        $this->assertSame('m2', $c->mensagens_sync_id);
        $this->assertSame(2, $c->mensagens()->count());
        $this->assertTrue(ShopeeChatMensagem::where('message_id', 'm2')->value('do_cliente'));
        $this->assertFalse(ShopeeChatMensagem::where('message_id', 'm1')->value('do_cliente'));
    }

    public function test_segundo_sync_sem_novidade_nao_busca_mensagens_nem_duplica(): void
    {
        $this->fakeShopee([$this->conversa()], [$this->conversa()], [$this->mensagem('m2', self::CLIENTE, 'Oi', 1761342060)]);
        $this->artisan('shopee:chat-sync')->assertSuccessful();

        Http::fake([
            '*get_conversation_list*' => Http::response(['error' => '', 'response' => ['conversations' => [$this->conversa()], 'page_result' => ['more' => false]]]),
        ]);
        $this->artisan('shopee:chat-sync')->assertSuccessful();

        $this->assertSame(1, ShopeeChatMensagem::count());
    }

    public function test_conversa_lida_no_app_da_shopee_zera_o_contador(): void
    {
        ShopeeChatConversa::create(['conversation_id' => 'c1', 'comprador_id' => self::CLIENTE, 'nao_lidas' => 2, 'ultima_mensagem_id' => 'm2', 'mensagens_sync_id' => 'm2']);

        $this->fakeShopee([], [$this->conversa('c1', 0)]);
        $this->artisan('shopee:chat-sync')->assertSuccessful();

        $this->assertSame(0, ShopeeChatConversa::first()->nao_lidas);
    }

    public function test_falha_numa_conversa_nao_derruba_as_outras(): void
    {
        $outra = $this->conversa('c2', 1, 'x2');
        Http::fake([
            '*get_conversation_list*type=unread*' => Http::response(['error' => '', 'response' => ['conversations' => [$this->conversa(), $outra], 'page_result' => ['more' => false]]]),
            '*get_conversation_list*type=all*' => Http::response(['error' => '', 'response' => ['conversations' => [], 'page_result' => ['more' => false]]]),
            '*conversation_id=c1*' => Http::response(['error' => 'error_server', 'message' => 'falha interna'], 500),
            '*conversation_id=c2*' => Http::response(['error' => '', 'response' => ['messages' => [$this->mensagem('x2', self::CLIENTE, 'Preço?', 1761342100)], 'page_result' => []]]),
        ]);

        $this->artisan('shopee:chat-sync')->assertSuccessful();

        $this->assertSame(1, ShopeeChatMensagem::count());
        $this->assertSame('x2', ShopeeChatConversa::where('conversation_id', 'c2')->value('mensagens_sync_id'));
        $this->assertNull(ShopeeChatConversa::where('conversation_id', 'c1')->value('mensagens_sync_id'));
    }

    public function test_limite_de_requisicoes_interrompe_o_passe_sem_erro(): void
    {
        Http::fake([
            '*get_conversation_list*type=unread*' => Http::response(['error' => '', 'response' => ['conversations' => [$this->conversa(), $this->conversa('c2', 1, 'x2')], 'page_result' => ['more' => false]]]),
            '*get_conversation_list*type=all*' => Http::response(['error' => '', 'response' => ['conversations' => [], 'page_result' => ['more' => false]]]),
            '*get_message*' => Http::response(['error' => 'error_rate_limit', 'message' => 'Too many requests'], 429),
        ]);

        $this->artisan('shopee:chat-sync')->assertSuccessful();

        Http::assertSentCount(3); // 2 listas + só 1 tentativa de mensagens
    }

    public function test_tela_lista_nao_lidas_primeiro_e_mostra_mensagens(): void
    {
        $lida = ShopeeChatConversa::create(['conversation_id' => 'a', 'comprador_id' => 1, 'comprador_nome' => 'Lida', 'nao_lidas' => 0, 'ultima_mensagem_em' => now()]);
        $nova = ShopeeChatConversa::create(['conversation_id' => 'b', 'comprador_id' => 2, 'comprador_nome' => 'Esperando', 'nao_lidas' => 3, 'ultima_mensagem_em' => now()->subDay()]);
        $nova->mensagens()->create(['message_id' => 'z', 'tipo' => 'text', 'do_cliente' => true, 'texto' => 'Preciso de ajuda', 'enviada_em' => now()]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/admin/chat-shopee')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('conversas.0.comprador', 'Esperando')
                ->where('selecionada.id', $nova->id)
                ->where('mensagens.0.texto', 'Preciso de ajuda')
                ->where('contagem.nao_lidas', 1)
                ->where('sidebarBadges.shopeeChatNaoLidas', 1));
    }

    public function test_enviar_resposta_so_com_acao_do_atendente_e_grava_a_mensagem(): void
    {
        $c = ShopeeChatConversa::create(['conversation_id' => 'c1', 'comprador_id' => self::CLIENTE, 'comprador_nome' => 'Maria', 'nao_lidas' => 1]);
        Http::fake(['*send_message*' => Http::response(['error' => '', 'response' => ['message_id' => 'novo1', 'created_timestamp' => 1761342200]])]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->postJson("/admin/chat-shopee/{$c->id}/mensagens", ['texto' => 'Seu pedido sai hoje.'])->assertOk();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'send_message')
            && $r['to_id'] === self::CLIENTE && $r['content']['text'] === 'Seu pedido sai hoje.');
        $m = ShopeeChatMensagem::where('message_id', 'novo1')->firstOrFail();
        $this->assertFalse($m->do_cliente);
        $this->assertSame($admin->id, $m->enviada_por_user_id);
        $this->assertSame(0, $c->fresh()->nao_lidas);
    }

    public function test_sync_nunca_envia_mensagem(): void
    {
        $this->fakeShopee([$this->conversa()], [$this->conversa()], [$this->mensagem('m2', self::CLIENTE, 'Oi', 1761342060)]);
        $this->artisan('shopee:chat-sync')->assertSuccessful();

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'send_message') || str_contains($r->url(), 'read_conversation'));
    }

    public function test_sem_permissao_nao_acessa(): void
    {
        $this->get('/admin/chat-shopee')->assertRedirect();
    }
}
