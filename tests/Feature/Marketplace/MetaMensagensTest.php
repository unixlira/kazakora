<?php

namespace Tests\Feature\Marketplace;

use App\Models\User;
use App\Modules\Marketplace\Models\MarketplaceChatConversa;
use App\Modules\Marketplace\Models\MarketplaceChatMensagem;
use App\Services\Meta\MetaMensagensService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaMensagensTest extends TestCase
{
    use RefreshDatabase;

    private const PAGINA = '1088740574320518';

    private const IG_LOJA = '1784000000000001';

    private function configurar(array $permissoes): void
    {
        config([
            'services.meta_ads.access_token' => 'token-usuario',
            'services.meta_ads.page_id' => self::PAGINA,
            'services.meta_ads.graph_url' => 'https://graph.test/v23.0',
        ]);

        Http::fake([
            'graph.test/v23.0/me/permissions*' => Http::response(['data' => collect($permissoes)->map(fn ($p) => ['permission' => $p, 'status' => 'granted'])->all()]),
            'graph.test/v23.0/'.self::PAGINA.'/conversations*' => function ($request) {
                $ig = str_contains($request->url(), 'platform=instagram');

                return Http::response(['data' => [[
                    'id' => $ig ? 'conv-ig-1' : 'conv-fb-1',
                    'updated_time' => '2026-10-27T20:00:00+0000',
                    'participants' => ['data' => $ig
                        ? [['username' => 'kazakora.co', 'id' => self::IG_LOJA], ['username' => 'maria.compras', 'id' => '555']]
                        : [['name' => 'Kaza Kora & CO', 'id' => self::PAGINA], ['name' => 'João Souza', 'id' => '777']]],
                ]]]);
            },
            'graph.test/v23.0/'.self::PAGINA.'*' => Http::response(['access_token' => 'token-pagina', 'instagram_business_account' => ['id' => self::IG_LOJA], 'id' => self::PAGINA]),
            'graph.test/v23.0/conv-ig-1*' => Http::response(['messages' => ['data' => [
                ['id' => 'ig-m2', 'created_time' => '2026-10-27T20:00:00+0000', 'from' => ['id' => '555'], 'message' => 'Ainda tem a caixa sanfonada?'],
                ['id' => 'ig-m1', 'created_time' => '2026-10-27T19:00:00+0000', 'from' => ['id' => self::IG_LOJA], 'message' => 'Oi! Em que posso ajudar?'],
            ]]]),
            'graph.test/v23.0/conv-fb-1*' => Http::response(['messages' => ['data' => [
                ['id' => 'fb-m1', 'created_time' => '2026-10-27T18:00:00+0000', 'from' => ['id' => '777'], 'message' => '', 'attachments' => ['data' => [['image_data' => ['url' => 'https://cdn.test/foto.jpg']]]]],
            ]]]),
        ]);
    }

    public function test_sync_separa_instagram_e_facebook_e_identifica_quem_falou(): void
    {
        $this->configurar(['instagram_basic', 'instagram_manage_messages', 'pages_manage_metadata', 'pages_messaging', 'pages_read_engagement']);

        $this->artisan('atendimento:meta-sync')->assertSuccessful();

        $ig = MarketplaceChatConversa::query()->where('canal', 'instagram')->sole();
        $this->assertSame('maria.compras', $ig->comprador_nome);
        $this->assertSame(1, $ig->nao_lidas);
        $this->assertSame('Ainda tem a caixa sanfonada?', $ig->ultima_mensagem_texto);
        $this->assertSame([false, true], $ig->mensagens()->orderBy('enviada_em')->pluck('do_cliente')->all());

        $fb = MarketplaceChatConversa::query()->where('canal', 'facebook')->sole();
        $this->assertSame('João Souza', $fb->comprador_nome);
        $this->assertSame('image', $fb->mensagens()->sole()->tipo);
        $this->assertSame('https://cdn.test/foto.jpg', $fb->mensagens()->sole()->conteudo['url']);

        // Nada é enviado à Meta: só GET.
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }

    public function test_segundo_sync_sem_mudanca_nao_duplica(): void
    {
        $this->configurar(['instagram_basic', 'instagram_manage_messages', 'pages_manage_metadata', 'pages_messaging', 'pages_read_engagement']);

        $this->artisan('atendimento:meta-sync')->assertSuccessful();
        MarketplaceChatConversa::query()->update(['nao_lidas' => 0]);
        $this->artisan('atendimento:meta-sync')->assertSuccessful();

        $this->assertSame(3, MarketplaceChatMensagem::query()->count());
        $this->assertSame(0, (int) MarketplaceChatConversa::query()->sum('nao_lidas'));
    }

    public function test_rede_sem_permissao_e_pulada_e_tela_mostra_o_que_falta(): void
    {
        $this->configurar(['ads_management', 'pages_show_list']);

        $this->artisan('atendimento:meta-sync')->assertSuccessful();
        $this->assertSame(0, MarketplaceChatConversa::query()->count());
        $this->assertContains('Permissão instagram_manage_messages no token da Meta', MetaMensagensService::faltando('instagram'));
        $this->assertContains('Permissão pages_messaging no token da Meta', MetaMensagensService::faltando('facebook'));

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->get('/admin/instagram/conversas')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/RedesSociais/Index', false)->where('rede', 'instagram')->where('faltando', fn ($f) => count($f) > 0));
    }

    public function test_tela_de_cada_rede_mostra_so_as_conversas_dela_e_marca_lida(): void
    {
        $ig = MarketplaceChatConversa::create(['canal' => 'instagram', 'externo_id' => 'a', 'comprador_nome' => 'maria', 'nao_lidas' => 2, 'ultima_mensagem_em' => now()]);
        MarketplaceChatConversa::create(['canal' => 'facebook', 'externo_id' => 'b', 'comprador_nome' => 'joao', 'nao_lidas' => 1, 'ultima_mensagem_em' => now()]);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/admin/instagram/conversas')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->has('conversas', 1)->where('conversas.0.cliente', 'maria')->where('contagem.nao_lidas', 1));

        $this->actingAs($admin)->postJson("/admin/facebook/conversas/{$ig->id}/lida")->assertNotFound();
        $this->actingAs($admin)->postJson("/admin/instagram/conversas/{$ig->id}/lida")->assertOk();
        $this->assertSame(0, $ig->fresh()->nao_lidas);

        $this->actingAs($admin)->get('/admin/whatsapp/conversas')->assertOk();
    }
}
