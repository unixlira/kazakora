<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Clientes\Models\ClienteEvento;
use App\Modules\FerraKora\Models\FerraKoraCliente;
use App\Modules\FerraKora\Models\FerraKoraSessao;
use App\Support\Privacidade\Cookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Clientes dos sites com filtro por loja, acompanhamento e linha do tempo
 * (pedido do Lira 26/11/2026: "quero rastreio de histórico total dos clientes").
 */
class ClientesHistoricoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Notification::fake();
        config(['services.ferrakora.origens' => 'ferrakora.com,ferrakora.devlira.com.br']);

        $this->admin = User::factory()->create(['role' => User::ROLE_ADMIN, 'email' => 'dono@kazakora.test']);
    }

    private function pedido(array $dono, string $status = Order::STATUS_PAID, float $total = 100): Order
    {
        $order = new Order;
        $order->forceFill([
            ...$dono,
            'status' => $status, 'origin' => Order::ORIGIN_STORE,
            'shipping_name' => 'Cliente', 'shipping_phone' => '11999999999', 'shipping_zip' => '01001000',
            'shipping_street' => 'Rua', 'shipping_number' => '1', 'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP', 'subtotal' => $total, 'total' => $total,
        ])->save();

        return $order;
    }

    private function pagamento(Order $order, string $metodo, string $status): void
    {
        DB::table('payments')->insert([
            'order_id' => $order->id, 'stripe_payment_intent_id' => 'pi_'.Str::random(12), 'provider' => 'mercadopago', 'method_type' => $metodo, 'amount' => $order->total,
            'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function ferra(array $dados = []): FerraKoraCliente
    {
        return FerraKoraCliente::query()->create([
            'name' => 'Cliente Ferra', 'email' => 'ferra@cliente.test', 'password' => 'segredo-123', ...$dados,
        ]);
    }

    public function test_filtro_por_loja_mostra_as_duas_lojas_juntas_ou_uma_so(): void
    {
        $kz = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'email' => 'kz@cliente.test', 'avatar_path' => 'avatars/a.jpg']);
        $fk = $this->ferra();
        User::factory()->create(['role' => User::ROLE_MANAGER, 'email' => 'equipe@kazakora.test']);

        $linhas = fn (string $query) => collect($this->actingAs($this->admin)->get('/admin/clientes-dos-sites?'.$query)
            ->assertOk()->viewData('page')['props']['clientes']['data']);

        $todas = $linhas('');
        $this->assertEqualsCanonicalizing(['kazakora:'.$kz->id, 'ferrakora:'.$fk->id], $todas->map(fn ($l) => $l['site'].':'.$l['id'])->all());
        $this->assertSame(['kz@cliente.test'], $linhas('site=kazakora')->pluck('email')->all());
        $this->assertSame(['ferra@cliente.test'], $linhas('site=ferrakora')->pluck('email')->all());
        $this->assertSame(['kz@cliente.test'], $linhas('foto=com')->pluck('email')->all());
        $this->assertSame(['ferra@cliente.test'], $linhas('foto=sem')->pluck('email')->all());
        $this->assertTrue($todas->firstWhere('site', 'kazakora')['tem_foto']);

        $this->actingAs($this->admin)->get('/admin/clientes-dos-sites')
            ->assertInertia(fn (Assert $page) => $page->where('site', 'todas')->where('podeVerHistorico', true));

        $csv = $this->actingAs($this->admin)->get('/admin/clientes-dos-sites/exportar')->streamedContent();
        $this->assertStringContainsString('Loja;Nome;E-mail', $csv);
        $this->assertStringContainsString('FerraKora;', $csv);
    }

    public function test_acompanhamento_traz_acesso_origem_pagamento_e_senha_so_para_admin(): void
    {
        $maria = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'email' => 'maria@cliente.test']);
        DB::table('site_visits')->insert([
            ['site' => 'kazakora', 'visitor_id' => 'v1', 'user_id' => $maria->id, 'path' => '/', 'origem' => 'instagram', 'utm_source' => 'ig', 'utm_campaign' => 'bf', 'consentiu' => true, 'created_at' => '2026-11-20 10:00:00'],
            ['site' => 'kazakora', 'visitor_id' => 'v1', 'user_id' => $maria->id, 'path' => '/produtos/x', 'origem' => 'interno', 'utm_source' => null, 'utm_campaign' => null, 'consentiu' => true, 'created_at' => '2026-11-22 10:00:00'],
        ]);
        $pedido = $this->pedido(['user_id' => $maria->id]);
        $this->pagamento($pedido, 'pix', 'canceled');
        $this->pagamento($pedido, 'card', 'captured');
        ClienteEvento::query()->create(['site' => 'kazakora', 'user_id' => $maria->id, 'tipo' => 'senha_redefinida', 'created_at' => '2026-11-23 08:00:00']);

        $linha = collect($this->actingAs($this->admin)->get('/admin/clientes-dos-sites?site=kazakora')
            ->viewData('page')['props']['clientes']['data'])->firstWhere('id', $maria->id);
        $a = $linha['acompanhamento'];

        $this->assertStringStartsWith('2026-11-22T10:00', $a['ultimo_acesso_em']);
        $this->assertSame(2, $a['dias_com_acesso']);
        $this->assertTrue($a['origem']['estimada']);
        $this->assertStringContainsString('utm: ig / bf', $a['origem']['texto']);
        $this->assertEqualsCanonicalizing(['pix' => false, 'card' => true], collect($a['pagamentos'])->mapWithKeys(fn ($p) => [$p['metodo'] => $p['aprovado']])->all());
        $this->assertSame('redefiniu', $a['senha']['status']);

        // Equipe com pedidos.view vê a lista, sem o acompanhamento e sem a linha do tempo.
        $gerente = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $linhaGerente = $this->actingAs($gerente)->get('/admin/clientes-dos-sites?site=kazakora')->assertOk()
            ->viewData('page')['props']['clientes']['data'][0];
        $this->assertNull($linhaGerente['acompanhamento']);
        $this->actingAs($gerente)->get("/admin/clientes-dos-sites/kazakora/{$maria->id}/historico")->assertForbidden();
        $this->actingAs($maria)->get("/admin/clientes-dos-sites/kazakora/{$maria->id}/historico")->assertForbidden();
    }

    public function test_origem_estimada_pelo_carrinho_ignora_interno(): void
    {
        $ana = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'email' => 'ana@cliente.test']);
        $bia = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'email' => 'bia@cliente.test']);
        DB::table('lead_checkout')->insert([
            ['site' => 'kazakora', 'email' => 'ana@cliente.test', 'origem' => 'interno', 'utm_source' => null, 'status' => 'aberto', 'etapa' => 'entrega', 'subtotal' => 10, 'quantidade_itens' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['site' => 'kazakora', 'email' => 'bia@cliente.test', 'origem' => 'interno', 'utm_source' => 'meta', 'status' => 'aberto', 'etapa' => 'entrega', 'subtotal' => 10, 'quantidade_itens' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $linhas = collect($this->actingAs($this->admin)->get('/admin/clientes-dos-sites?site=kazakora')->viewData('page')['props']['clientes']['data']);

        $this->assertNull($linhas->firstWhere('id', $ana->id)['acompanhamento']['origem']);
        $this->assertSame('utm: meta', $linhas->firstWhere('id', $bia->id)['acompanhamento']['origem']['texto']);
    }

    public function test_login_na_kazakora_grava_evento_e_liga_a_navegacao_anterior_com_aceite(): void
    {
        $maria = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'email' => 'maria@cliente.test', 'password' => bcrypt('segredo-123')]);
        $visitante = (string) Str::uuid();
        $outro = (string) Str::uuid();
        DB::table('site_visits')->insert([
            ['site' => 'kazakora', 'visitor_id' => $visitante, 'user_id' => null, 'path' => '/produtos/a', 'consentiu' => true, 'created_at' => now()->subDay()],
            ['site' => 'kazakora', 'visitor_id' => $visitante, 'user_id' => null, 'path' => '/velha', 'consentiu' => true, 'created_at' => now()->subMonths(7)],
            ['site' => 'kazakora', 'visitor_id' => $outro, 'user_id' => null, 'path' => '/de-outra-pessoa', 'consentiu' => true, 'created_at' => now()->subDay()],
        ]);

        $this->withCookie(Cookies::COOKIE_OK, Cookies::VERSAO)->withCookie(Cookies::COOKIE_VISITANTE, $visitante)
            ->post('/entrar', ['email' => 'maria@cliente.test', 'password' => 'segredo-123'])->assertRedirect();

        $login = ClienteEvento::query()->where('user_id', $maria->id)->where('tipo', 'login')->sole();
        $this->assertSame('senha', $login->detalhe['como']);
        $this->assertSame(['/produtos/a'], DB::table('site_visits')->where('user_id', $maria->id)->pluck('path')->all(), 'só o mesmo navegador e só dentro de 6 meses');
        $this->assertSame(1, ClienteEvento::query()->where('user_id', $maria->id)->where('tipo', 'visitas_ligadas')->sole()->detalhe['visitas']);

        $this->post('/sair');
        $this->assertTrue(ClienteEvento::query()->where('user_id', $maria->id)->where('tipo', 'logout')->exists());
    }

    public function test_login_sem_aceite_de_cookies_nao_liga_a_navegacao(): void
    {
        $maria = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'email' => 'maria@cliente.test', 'password' => bcrypt('segredo-123')]);
        $visitante = (string) Str::uuid();
        DB::table('site_visits')->insert(['site' => 'kazakora', 'visitor_id' => $visitante, 'path' => '/produtos/a', 'created_at' => now()->subHour()]);

        $this->withCookie(Cookies::COOKIE_VISITANTE, $visitante)
            ->post('/entrar', ['email' => 'maria@cliente.test', 'password' => 'segredo-123'])->assertRedirect();

        $this->assertTrue(ClienteEvento::query()->where('user_id', $maria->id)->where('tipo', 'login')->exists());
        $this->assertSame(0, DB::table('site_visits')->where('user_id', $maria->id)->count());
    }

    public function test_equipe_nao_ganha_historico_e_link_de_senha_fica_registrado(): void
    {
        $this->admin->sendPasswordResetNotification('x');
        $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $cliente->sendPasswordResetNotification('x');

        $this->assertSame(0, ClienteEvento::query()->where('user_id', $this->admin->id)->count());
        $this->assertTrue(ClienteEvento::query()->where('user_id', $cliente->id)->where('tipo', 'senha_link_enviado')->exists());
    }

    public function test_ferrakora_cadastro_login_senha_e_saida_ficam_no_historico(): void
    {
        $this->postJson('/api/ferrakora/cadastro', [
            'name' => 'Zé', 'email' => 'ze@cliente.test', 'password' => 'segredo-123',
            'origem' => ['utm_source' => 'facebook', 'utm_medium' => 'cpc', 'utm_campaign' => 'compressor', 'clique' => 'fbclid', 'ref' => 'https://m.facebook.com/x?y=1'],
        ])->assertCreated();
        $ze = FerraKoraCliente::query()->where('email', 'ze@cliente.test')->sole();

        $cadastro = ClienteEvento::query()->where('ferrakora_cliente_id', $ze->id)->where('tipo', 'cadastro')->sole();
        $this->assertSame('compressor', $cadastro->detalhe['origem']['utm_campaign']);
        $this->assertSame('m.facebook.com', $cadastro->detalhe['origem']['referer']);
        $this->assertSame('fbclid', $cadastro->detalhe['origem']['clique']);

        $token = $this->postJson('/api/ferrakora/login', ['email' => 'ze@cliente.test', 'password' => 'segredo-123'])->assertOk()->json('token');
        $this->postJson('/api/ferrakora/login', ['email' => 'ze@cliente.test', 'password' => 'errada'])->assertStatus(422);
        $this->postJson('/api/ferrakora/esqueci-senha', ['email' => 'ze@cliente.test'])->assertOk();
        $this->withToken($token)->postJson('/api/ferrakora/sair')->assertOk();

        $this->assertSame(['cadastro', 'login', 'senha_link_enviado', 'logout'], ClienteEvento::query()->where('ferrakora_cliente_id', $ze->id)->orderBy('id')->pluck('tipo')->all());
    }

    public function test_visita_da_ferrakora_so_ganha_dono_com_conta_logada_e_aceito(): void
    {
        $ze = $this->ferra();
        $token = FerraKoraSessao::abrir($ze, Request::create('/'));
        $headers = ['Origin' => 'https://ferrakora.com', 'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36'];

        $this->withHeaders($headers)->post('/api/ferrakora/visita?p=/produto/a&e=0', ['t' => $token, 'ac' => '1'])->assertNoContent();
        $this->withHeaders($headers)->post('/api/ferrakora/visita?p=/produto/b&e=0', ['t' => $token])->assertNoContent();
        $this->withHeaders($headers)->post('/api/ferrakora/visita?p=/produto/c&e=0', ['t' => 'token-falso', 'ac' => '1'])->assertNoContent();

        $this->assertSame(['/produto/a'], DB::table('site_visits')->where('ferrakora_cliente_id', $ze->id)->pluck('path')->all());
        $this->assertSame(3, DB::table('site_visits')->where('site', 'ferrakora')->count());
    }

    public function test_linha_do_tempo_junta_tudo_em_ordem_e_respeita_o_aceite(): void
    {
        $maria = User::factory()->create(['role' => User::ROLE_CUSTOMER, 'email' => 'maria@cliente.test', 'phone' => '(11) 98765-4321']);
        $maria->forceFill(['created_at' => '2026-11-01 09:00:00'])->save();
        ClienteEvento::query()->create(['site' => 'kazakora', 'user_id' => $maria->id, 'tipo' => 'login', 'detalhe' => ['como' => 'senha'], 'created_at' => '2026-11-02 10:00:00']);
        DB::table('site_visits')->insert([
            ['site' => 'kazakora', 'visitor_id' => 'v1', 'user_id' => $maria->id, 'path' => '/produtos/compressor', 'consentiu' => true, 'created_at' => '2026-11-02 10:01:00'],
            ['site' => 'kazakora', 'visitor_id' => 'v1', 'user_id' => $maria->id, 'path' => '/carrinho', 'consentiu' => true, 'created_at' => '2026-11-02 10:03:30'],
            ['site' => 'kazakora', 'visitor_id' => 'v2', 'user_id' => $maria->id, 'path' => '/pagina-secreta', 'consentiu' => false, 'created_at' => '2026-11-03 11:00:00'],
        ]);
        $pedido = $this->pedido(['user_id' => $maria->id], Order::STATUS_PAID, 300);
        $pedido->forceFill(['created_at' => '2026-11-02 10:05:00', 'coupon_code' => 'BEMVINDO'])->save();
        $this->pagamento($pedido, 'pix', 'captured');
        DB::table('payments')->update(['created_at' => '2026-11-02 10:05:10']);
        DB::table('order_email_logs')->insert(['order_id' => $pedido->id, 'mailable' => 'order_confirmation', 'attempt' => 1, 'status' => 'sent', 'created_at' => '2026-11-02 10:06:00']);
        $conversa = DB::table('whatsapp_conversations')->insertGetId(['wa_id' => '5511987654321', 'phone' => '5511987654321', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('whatsapp_messages')->insert(['conversation_id' => $conversa, 'direction' => 'outbound', 'type' => 'text', 'body' => 'Seu pedido foi aprovado', 'status' => 'delivered', 'sent_at' => '2026-11-02 10:07:00', 'created_at' => now(), 'updated_at' => now()]);
        $resposta = $this->actingAs($this->admin)->get("/admin/clientes-dos-sites/kazakora/{$maria->id}/historico")->assertOk();

        $resposta->assertInertia(fn (Assert $page) => $page
            ->component('Admin/ClientesDosSites/Historico', false)
            ->where('eventos', function ($eventos) {
                $eventos = collect($eventos);
                $titulos = $eventos->pluck('titulo')->all();
                $this->assertSame('Cadastro na KazaKora', $titulos[0]);
                $this->assertSame('Entrou na conta', $titulos[1]);
                $this->assertSame('Abriu /produtos/compressor', $titulos[2]);
                $this->assertSame(150, $eventos[2]['tempo_segundos'], 'tempo até a próxima página do mesmo navegador');
                $this->assertContains('Abriu /carrinho', $titulos);
                $this->assertContains('Acessou o site logado', $titulos);
                $this->assertNotContains('Abriu /pagina-secreta', $titulos, 'sem aceite a página não aparece');
                $this->assertContains('Pagamento por Pix (pedido #'.$eventos->firstWhere('categoria', 'pedido')['pedido_id'].')', $titulos);
                $this->assertContains('E-mail: confirmação do pedido', $titulos);
                $this->assertContains('WhatsApp enviado', $titulos);
                $this->assertContains('Usou o cupom BEMVINDO', $titulos);
                $ems = $eventos->pluck('em')->map(fn ($e) => strtotime($e))->all();
                $ordenado = $ems;
                sort($ordenado);
                $this->assertSame($ordenado, $ems, 'em ordem cronológica');

                return true;
            })
            ->has('fontes')
            ->where('acompanhamento.pagamentos.0.nome', 'Pix'));

        $this->assertDatabaseHas('audit_logs', ['user_id' => $this->admin->id, 'action' => 'view', 'entity' => 'HistoricoDoCliente', 'entity_id' => $maria->id]);
    }

    public function test_linha_do_tempo_da_ferrakora_com_sessao_antiga_e_paginas_logado(): void
    {
        $ze = $this->ferra(['phone' => '11911112222']);
        DB::table('ferrakora_sessoes')->insert(['cliente_id' => $ze->id, 'token_hash' => str_repeat('b', 64), 'expira_em' => now()->addDays(5), 'ultimo_uso_em' => '2026-11-20 12:00:00', 'created_at' => '2026-11-18 08:00:00', 'updated_at' => now()]);
        DB::table('site_visits')->insert(['site' => 'ferrakora', 'visitor_id' => 'ip', 'ferrakora_cliente_id' => $ze->id, 'path' => '/produto/serra', 'created_at' => '2026-11-26 09:00:00']);

        $this->actingAs($this->admin)->get("/admin/clientes-dos-sites/ferrakora/{$ze->id}/historico")->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('eventos', function ($eventos) {
                    $titulos = collect($eventos)->pluck('titulo')->all();
                    $this->assertContains('Cadastro na FerraKora', $titulos);
                    $this->assertContains('Entrou na conta', $titulos);
                    $this->assertContains('Abriu /produto/serra', $titulos);

                    return true;
                }));
    }

    public function test_limpeza_apaga_login_antigo_e_tira_ip_dos_outros_eventos(): void
    {
        $maria = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        ClienteEvento::query()->create(['site' => 'kazakora', 'user_id' => $maria->id, 'tipo' => 'login', 'ip' => '1.2.3.4', 'created_at' => now()->subMonths(7)]);
        ClienteEvento::query()->create(['site' => 'kazakora', 'user_id' => $maria->id, 'tipo' => 'foto_trocada', 'ip' => '1.2.3.4', 'created_at' => now()->subMonths(7)]);
        ClienteEvento::query()->create(['site' => 'kazakora', 'user_id' => $maria->id, 'tipo' => 'login', 'ip' => '1.2.3.4', 'created_at' => now()->subDay()]);

        $this->artisan('privacidade:limpar')->assertSuccessful();

        $this->assertSame(1, ClienteEvento::query()->where('tipo', 'login')->count());
        $this->assertNull(ClienteEvento::query()->where('tipo', 'foto_trocada')->sole()->ip);
    }
}
