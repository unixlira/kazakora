<?php

namespace Tests\Feature\Marketplace;

use App\Models\User;
use App\Modules\Marketplace\Models\MarketplaceChatConversa;
use App\Modules\Marketplace\Models\MarketplaceChatMensagem;
use App\Modules\Marketplace\Models\TikTokShopConexao;
use App\Modules\Marketplace\Support\AmazonMensagensService;
use App\Services\Email\EmailLido;
use App\Services\Email\ImapCliente;
use App\Services\TikTokShop\TikTokShopClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class MarketplaceChatTest extends TestCase
{
    use RefreshDatabase;

    private const COMPRADOR = 'abc123xyz@marketplace.amazon.com.br';

    private function emailAmazon(string $id = 'msg-1@amazon', string $texto = 'Olá, o produto vem com pilhas?'): string
    {
        $qp = quoted_printable_encode("------------- Mensagem: -------------\n\n{$texto}\n\n------------- Fim da mensagem -------------\n\nPara responder, responda a este e-mail.");

        return implode("\r\n", [
            'From: =?UTF-8?Q?Jo=C3=A3o_Silva_-_Amazon_Marketplace?= <'.self::COMPRADOR.'>',
            'To: atendimento@kazakora.com',
            'Subject: =?UTF-8?Q?Consulta_do_cliente_sobre_o_pedido_701-1234567-7654321?=',
            'Date: Sun, 25 Oct 2026 13:00:00 +0000',
            "Message-ID: <{$id}>",
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="XYZ"',
            '',
            '--XYZ',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: quoted-printable',
            '',
            $qp,
            '--XYZ',
            'Content-Type: text/html; charset=UTF-8',
            '',
            '<p>html</p>',
            '--XYZ--',
            '',
        ]);
    }

    private function configurarAmazon(): void
    {
        config([
            'services.amazon_mensagens.email' => 'atendimento@kazakora.com',
            'services.amazon_mensagens.imap_host' => 'imap.teste',
            'services.amazon_mensagens.imap_senha' => 'x',
            'mail.mailers.amazon_mensagens' => ['transport' => 'array', 'host' => 'smtp.teste'],
        ]);
    }

    private function configurarTikTok(): TikTokShopConexao
    {
        config([
            'services.tiktok_shop.app_key' => 'chave',
            'services.tiktok_shop.app_secret' => 'segredo',
            'services.tiktok_shop.service_id' => '777',
            'services.tiktok_shop.api_url' => 'https://tts.test',
        ]);

        return TikTokShopConexao::create([
            'access_token' => 'tok', 'refresh_token' => 'ref', 'access_expira_em' => now()->addDay(),
            'refresh_expira_em' => now()->addMonth(), 'shop_cipher' => 'CIPHER',
        ]);
    }

    public function test_email_da_amazon_e_lido_com_nome_pedido_e_so_a_mensagem(): void
    {
        $email = EmailLido::de($this->emailAmazon());

        $this->assertSame('Consulta do cliente sobre o pedido 701-1234567-7654321', $email->assunto());
        $this->assertSame(self::COMPRADOR, $email->remetente()['email']);
        $this->assertSame('Olá, o produto vem com pilhas?', AmazonMensagensService::mensagemDoCorpo($email->texto));
    }

    public function test_registrar_cria_conversa_e_nao_duplica(): void
    {
        $servico = app(AmazonMensagensService::class);

        $this->assertTrue($servico->registrar(EmailLido::de($this->emailAmazon())));
        $this->assertFalse($servico->registrar(EmailLido::de($this->emailAmazon())));
        $this->assertTrue($servico->registrar(EmailLido::de($this->emailAmazon('msg-2@amazon', 'E a garantia?'))));

        $c = MarketplaceChatConversa::where('canal', 'amazon')->sole();
        $this->assertSame(self::COMPRADOR, $c->externo_id);
        $this->assertSame('João Silva', $c->comprador_nome);
        $this->assertSame('701-1234567-7654321', $c->pedido_numero);
        $this->assertSame(2, $c->nao_lidas);
        $this->assertSame(2, $c->mensagens()->count());
    }

    public function test_email_que_nao_e_de_comprador_e_ignorado(): void
    {
        $cru = str_replace(self::COMPRADOR, 'donotreply@amazon.com.br', $this->emailAmazon());

        $this->assertFalse(app(AmazonMensagensService::class)->registrar(EmailLido::de($cru)));
        $this->assertSame(0, MarketplaceChatConversa::count());
    }

    public function test_sync_da_amazon_le_a_caixa_por_imap_e_lembra_o_ultimo_uid(): void
    {
        $this->configurarAmazon();
        $imap = Mockery::mock(ImapCliente::class);
        $imap->shouldReceive('conectar', 'login', 'sair')->once();
        $imap->shouldReceive('selecionar')->once()->andReturn(99);
        $imap->shouldReceive('buscarUids')->once()->with(Mockery::pattern('/^SINCE .* FROM "marketplace.amazon"$/'))->andReturn([5, 8]);
        $imap->shouldReceive('baixar')->with(5)->andReturn($this->emailAmazon('a@amazon'));
        $imap->shouldReceive('baixar')->with(8)->andReturn($this->emailAmazon('b@amazon', 'Chegou quebrado'));
        $this->app->instance(ImapCliente::class, $imap);

        $this->artisan('atendimento:chat-sync', ['--canal' => 'amazon'])->assertSuccessful();

        $this->assertSame(2, MarketplaceChatMensagem::count());
        $this->assertSame(['validade' => 99, 'uid' => 8], cache()->get('amazon-mensagens:imap'));
    }

    public function test_responder_amazon_manda_email_ao_endereco_do_comprador(): void
    {
        $this->configurarAmazon();
        app(AmazonMensagensService::class)->registrar(EmailLido::de($this->emailAmazon()));
        $c = MarketplaceChatConversa::sole();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->postJson("/admin/chat-marketplace/amazon/{$c->id}/mensagens", ['texto' => 'Vem sim, 2 pilhas AA.'])->assertOk();

        $enviados = app('mail.manager')->mailer('amazon_mensagens')->getSymfonyTransport()->messages();
        $this->assertCount(1, $enviados);
        $msg = $enviados->first()->getOriginalMessage();
        $this->assertSame(self::COMPRADOR, $msg->getTo()[0]->getAddress());
        $this->assertSame('atendimento@kazakora.com', $msg->getFrom()[0]->getAddress());
        $this->assertStringStartsWith('Re: Consulta do cliente', $msg->getSubject());
        $this->assertSame(0, $c->fresh()->nao_lidas);
        $this->assertFalse($c->fresh()->ultima_do_cliente);
        $this->assertSame($admin->id, MarketplaceChatMensagem::where('do_cliente', false)->value('enviada_por_user_id'));
    }

    public function test_canal_errado_na_rota_nao_envia(): void
    {
        $c = MarketplaceChatConversa::create(['canal' => 'amazon', 'externo_id' => self::COMPRADOR]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->postJson("/admin/chat-marketplace/tiktok_shop/{$c->id}/mensagens", ['texto' => 'x'])->assertNotFound();
    }

    public function test_assinatura_do_tiktok(): void
    {
        $esperado = hash_hmac('sha256', 'segredo/api/teste'.'a1'.'b2'.'{"x":1}'.'segredo', 'segredo');

        $this->assertSame($esperado, TikTokShopClient::assinar('/api/teste', ['b' => 2, 'a' => 1, 'sign' => 'z', 'access_token' => 't'], '{"x":1}', 'segredo'));
    }

    public function test_sync_do_tiktok_grava_conversa_e_mensagens(): void
    {
        $this->configurarTikTok();
        Http::fake([
            'tts.test/customer_service/202309/conversations?*' => Http::response(['code' => 0, 'data' => ['conversations' => [[
                'id' => 'conv1', 'unread_count' => 1,
                'participants' => [['role' => 'BUYER', 'nickname' => 'ana.tt', 'avatar' => 'https://img/ana.png'], ['role' => 'SHOP', 'nickname' => 'KazaKora']],
                'latest_message' => ['id' => 'm2', 'type' => 'TEXT', 'content' => '{"content":"Tem azul?"}', 'create_time' => 1761397200, 'sender' => ['role' => 'BUYER']],
            ]]]]),
            'tts.test/customer_service/202309/conversations/conv1/messages?*' => Http::response(['code' => 0, 'data' => ['messages' => [
                ['id' => 'm2', 'type' => 'TEXT', 'content' => '{"content":"Tem azul?"}', 'create_time' => 1761397200, 'sender' => ['role' => 'BUYER']],
                ['id' => 'm1', 'type' => 'TEXT', 'content' => '{"content":"Olá!"}', 'create_time' => 1761397100, 'sender' => ['role' => 'SHOP']],
            ]]]),
        ]);

        $this->artisan('atendimento:chat-sync', ['--canal' => 'tiktok_shop'])->assertSuccessful();

        $c = MarketplaceChatConversa::where('canal', 'tiktok_shop')->sole();
        $this->assertSame('ana.tt', $c->comprador_nome);
        $this->assertSame(1, $c->nao_lidas);
        $this->assertSame('m2', $c->mensagens_sync_id);
        $this->assertTrue(MarketplaceChatMensagem::where('externo_id', 'm2')->value('do_cliente'));
        $this->assertFalse(MarketplaceChatMensagem::where('externo_id', 'm1')->value('do_cliente'));
        Http::assertSent(fn ($r) => $r->hasHeader('x-tts-access-token', 'tok') && str_contains($r->url(), 'shop_cipher=CIPHER') && str_contains($r->url(), 'sign='));
        Http::assertNotSent(fn ($r) => $r->method() === 'POST');
    }

    public function test_responder_tiktok_posta_texto_na_conversa(): void
    {
        $this->configurarTikTok();
        $c = MarketplaceChatConversa::create(['canal' => 'tiktok_shop', 'externo_id' => 'conv1', 'nao_lidas' => 2]);
        Http::fake(['tts.test/customer_service/202309/conversations/conv1/messages?*' => Http::response(['code' => 0, 'data' => ['message_id' => 'novo9']])]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->postJson("/admin/chat-marketplace/tiktok_shop/{$c->id}/mensagens", ['texto' => 'Tem sim!'])->assertOk();

        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['type'] === 'TEXT' && json_decode($r['content'], true) === ['content' => 'Tem sim!']);
        $this->assertSame(0, $c->fresh()->nao_lidas);
        $this->assertTrue(MarketplaceChatMensagem::where('externo_id', 'novo9')->exists());
    }

    public function test_aba_sem_configuracao_mostra_o_que_falta_e_sync_pula(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/admin/perguntas-marketplace?aba=chat-amazon')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('aba', 'chat-amazon')
                ->where('chatMarketplace.canal', 'amazon')
                ->where('chatMarketplace.configurado', false)
                ->where('chatMarketplace.faltando', fn ($f) => count($f) > 0));

        $this->actingAs($admin)->get('/admin/perguntas-marketplace?aba=chat-tiktok')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->where('chatMarketplace.canal', 'tiktok_shop')->where('chatMarketplace.configurado', false));

        $this->artisan('atendimento:chat-sync')->assertSuccessful();
    }

    public function test_contadores_somam_amazon_e_tiktok(): void
    {
        MarketplaceChatConversa::create(['canal' => 'amazon', 'externo_id' => 'a@marketplace.amazon.com.br', 'nao_lidas' => 1]);
        MarketplaceChatConversa::create(['canal' => 'tiktok_shop', 'externo_id' => 'c9', 'nao_lidas' => 4]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/admin/perguntas-marketplace')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('abas.chat-amazon', 1)
                ->where('abas.chat-tiktok', 1)
                ->where('sidebarBadges.atendimentoMkplace', 2));
    }

    public function test_retorno_do_tiktok_com_estado_errado_nao_conecta(): void
    {
        $this->configurarTikTok();
        TikTokShopConexao::query()->delete();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->withSession(['tiktok_shop_estado' => 'certo'])
            ->get('/admin/integracoes/tiktok-shop/retorno?code=abc&state=errado')
            ->assertRedirect('/admin/perguntas-marketplace?aba=chat-tiktok');

        $this->assertSame(0, TikTokShopConexao::count());
    }
}
