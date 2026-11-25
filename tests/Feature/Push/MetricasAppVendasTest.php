<?php

namespace Tests\Feature\Push;

use App\Models\User;
use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Checkout\Models\Order;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Payment;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use App\Modules\Marketplace\Models\ChannelAdSpend;
use App\Modules\Notifications\Push\TokensDoApp;
use App\Modules\Marketplace\Support\ContributionMargin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Tela principal dos apps de vendas: métricas só da loja do app (2026-11-19). */
class MetricasAppVendasTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $agora;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agora = Carbon::parse('2026-11-19 15:00:00');
        Carbon::setTestNow($this->agora);
        Cache::flush();
        config([
            'services.meta_ads.access_token' => '',
            'services.meta_ads.ad_account_id' => '',
            'services.meta_ads.page_id' => '',
            'services.meta_ads.ferrakora_ad_account_id' => '',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Requisições com o token do app, como o app faz. */
    private function api(User $user, string $app): static
    {
        return $this->withToken(TokensDoApp::emitir($user, $app)['token']);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN, 'name' => 'Lira Teste']);
    }

    private function pedido(string $origin, ?string $site, string $status, float $total, string $quando, string $produto = 'Parafusadeira 48V', string $metodo = Payment::METHOD_CARD): Order
    {
        $order = Order::create([
            'user_id' => User::factory()->create(['name' => 'Maria Cliente Sigilosa'])->id,
            'origin' => $origin,
            'site_origem' => $site,
            'external_order_id' => 'PED-'.uniqid(),
            'status' => $status,
            'shipping_name' => 'Maria Cliente Sigilosa',
            'shipping_phone' => '11988887777',
            'shipping_zip' => '01310-100',
            'shipping_street' => 'Rua Secreta',
            'shipping_number' => '1000',
            'shipping_neighborhood' => 'Bela Vista',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => $total,
            'total' => $total,
        ]);
        $order->forceFill(['created_at' => Carbon::parse($quando)])->save();
        $order->items()->create(['product_name' => $produto, 'product_price' => $total, 'quantity' => 1, 'subtotal' => $total]);
        $order->payments()->create(['provider' => Payment::PROVIDER_MERCADOPAGO, 'method_type' => $metodo, 'amount' => $total, 'status' => Payment::STATUS_CAPTURED]);

        return $order;
    }

    private function visita(string $site, string $visitante, string $quando): void
    {
        $v = SiteVisit::create(['site' => $site, 'visitor_id' => $visitante, 'path' => '/', 'ip' => '10.0.0.'.crc32($visitante) % 250, 'consentiu' => true]);
        $v->forceFill(['created_at' => Carbon::parse($quando)])->save();
    }

    /** @return array<string, Order> */
    private function cenario(): array
    {
        $p = [
            'kz1' => $this->pedido(Order::ORIGIN_STORE, null, Order::STATUS_PAID, 110.00, '2026-11-19 09:00', 'Árvore de Natal'),
            'kz2' => $this->pedido(Order::ORIGIN_STORE, null, Order::STATUS_SHIPPED, 200.00, '2026-11-19 11:00', 'Luminária'),
            'kzPix' => $this->pedido(Order::ORIGIN_STORE, null, Order::STATUS_AWAITING_PAYMENT, 50.00, '2026-11-19 12:00', 'Luminária', Payment::METHOD_PIX),
            'kzCancelado' => $this->pedido(Order::ORIGIN_STORE, null, Order::STATUS_CANCELLED, 70.00, '2026-11-19 13:00'),
            // Ontem antes das 15h entra na comparação; ontem às 16h não.
            'kzOntem' => $this->pedido(Order::ORIGIN_STORE, null, Order::STATUS_PAID, 80.00, '2026-11-18 10:00'),
            'kzOntemTarde' => $this->pedido(Order::ORIGIN_STORE, null, Order::STATUS_PAID, 1000.00, '2026-11-18 16:00'),
            'fk' => $this->pedido(Order::ORIGIN_STORE, Order::SITE_FERRAKORA, Order::STATUS_PAID, 300.00, '2026-11-19 10:00', 'Compressor'),
            'bk' => $this->pedido(Order::ORIGIN_STORE, Order::SITE_BEAUTYKORA, Order::STATUS_PAID, 444.00, '2026-11-19 10:00'),
            'ml' => $this->pedido(Order::ORIGIN_MERCADO_LIVRE, null, Order::STATUS_PAID, 999.00, '2026-11-19 10:00'),
            'shopee' => $this->pedido(Order::ORIGIN_SHOPEE, null, Order::STATUS_PAID, 555.00, '2026-11-19 14:00'),
        ];

        foreach (['a', 'b', 'c', 'd'] as $v) {
            $this->visita('kazakora', 'kz-'.$v, '2026-11-19 08:00');
        }
        $this->visita('kazakora', 'kz-ontem', '2026-11-18 08:00');
        $this->visita('ferrakora', 'fk-a', '2026-11-19 08:00');

        return $p;
    }

    public function test_kazakora_so_conta_a_loja_kazakora_e_os_numeros_batem(): void
    {
        $p = $this->cenario();

        $r = $this->api($this->admin(), 'kazakora')->getJson('/api-apps-vendas/kazakora/metricas?periodo=hoje')->assertOk();

        $r->assertJsonPath('loja', 'kazakora.com')
            ->assertJsonPath('faturamento.valor', 310)
            ->assertJsonPath('faturamento.anterior', 80)
            ->assertJsonPath('faturamento.variacao', 287.5)
            ->assertJsonPath('pedidos.valor', 2)
            ->assertJsonPath('ticket_medio.valor', 155)
            ->assertJsonPath('aguardando_pagamento.pedidos', 1)
            ->assertJsonPath('aguardando_pagamento.valor', 50)
            ->assertJsonPath('visitas.valor', 4)
            ->assertJsonPath('visitas.anterior', 1)
            ->assertJsonPath('conversao.valor', 50)
            ->assertJsonPath('meta.configurada', false)
            ->assertJsonPath('top_produtos.0.nome', 'Luminária')
            ->assertJsonCount(2, 'top_produtos');

        // Gráfico por hora: 00h até a hora atual; visitas na 1ª página, pedidos pagos.
        $serie = collect($r->json('serie'))->keyBy('rotulo');
        $this->assertCount(16, $serie);
        $this->assertSame(4, $serie['08h']['visitas']);
        $this->assertSame(1, $serie['09h']['pedidos']);
        $this->assertSame(1, $serie['11h']['pedidos']);
        $this->assertSame(0, $serie['12h']['pedidos'], 'Pix pendente não é pedido pago');
        $this->assertSame(4, $serie->sum('visitas'));

        // Lucro = a conta oficial, linha do kazakora.com.
        $oficial = app(ContributionMargin::class)->porCanal($this->agora->copy()->startOfDay(), $this->agora->copy()->startOfDay()->addDay())['loja']['lucro'];
        $this->assertEquals($oficial, $r->json('lucro.valor'));
        $this->assertNull($r->json('lucro.variacao'), 'hoje não compara lucro com o dia inteiro de ontem');

        $ids = collect($r->json('ultimos_pedidos'))->pluck('id')->all();
        $this->assertEqualsCanonicalizing(
            [$p['kz1']->id, $p['kz2']->id, $p['kzPix']->id, $p['kzCancelado']->id, $p['kzOntem']->id, $p['kzOntemTarde']->id],
            $ids,
        );
        $this->assertSame($p['kzCancelado']->id, $ids[0]);
        $this->assertSame('Aguardando pagamento', collect($r->json('ultimos_pedidos'))->firstWhere('id', $p['kzPix']->id)['status_rotulo']);
        $this->assertStringNotContainsString('Maria', $r->getContent());
        $this->assertStringNotContainsString('Secreta', $r->getContent());
    }

    public function test_ferrakora_nao_mistura_kazakora_nem_marketplace(): void
    {
        $p = $this->cenario();

        $r = $this->api($this->admin(), 'ferrakora')->getJson('/api-apps-vendas/ferrakora/metricas?periodo=7d')->assertOk();

        $r->assertJsonPath('loja', 'ferrakora.com')
            ->assertJsonPath('faturamento.valor', 300)
            ->assertJsonPath('pedidos.valor', 1)
            ->assertJsonPath('aguardando_pagamento.pedidos', 0)
            ->assertJsonPath('visitas.valor', 1)
            ->assertJsonPath('top_produtos.0.nome', 'Compressor')
            ->assertJsonCount(1, 'top_produtos');
        $this->assertSame([$p['fk']->id], collect($r->json('ultimos_pedidos'))->pluck('id')->all());

        $oficial = app(ContributionMargin::class)->porCanal($this->agora->copy()->startOfDay()->subDays(6), $this->agora->copy()->startOfDay()->addDay())['ferrakora']['lucro'];
        $this->assertEquals($oficial, $r->json('lucro.valor'));
    }

    public function test_sete_dias_compara_com_os_sete_anteriores(): void
    {
        $this->cenario();
        $this->pedido(Order::ORIGIN_STORE, null, Order::STATUS_PAID, 90.00, '2026-11-10 10:00');

        $this->api($this->admin(), 'kazakora')->getJson('/api-apps-vendas/kazakora/metricas?periodo=7d')->assertOk()
            ->assertJsonPath('faturamento.valor', 1390)
            ->assertJsonPath('pedidos.valor', 4)
            ->assertJsonPath('faturamento.anterior', 90)
            ->assertJsonCount(7, 'serie')
            ->assertJsonPath('serie.6.rotulo', '19/11')
            ->assertJsonPath('serie.6.pedidos', 2)
            ->assertJsonPath('serie.6.visitas', 4)
            ->assertJsonPath('serie.5.pedidos', 2)
            ->assertJsonPath('serie.5.visitas', 1);
    }

    public function test_mes_corrente_compara_com_o_mesmo_intervalo_do_mes_anterior(): void
    {
        $this->cenario();
        $this->pedido(Order::ORIGIN_STORE, null, Order::STATUS_PAID, 40.00, '2026-11-01 00:30');
        $this->pedido(Order::ORIGIN_STORE, null, Order::STATUS_PAID, 25.00, '2026-10-05 10:00');
        $this->pedido(Order::ORIGIN_STORE, null, Order::STATUS_PAID, 500.00, '2026-10-25 10:00'); // depois de 19/10: fora
        $this->pedido(Order::ORIGIN_STORE, null, Order::STATUS_PAID, 600.00, '2026-10-31 23:00'); // mês anterior: fora do atual

        $this->api($this->admin(), 'kazakora')->getJson('/api-apps-vendas/kazakora/metricas')->assertOk()
            ->assertJsonPath('periodo', 'mes')
            ->assertJsonPath('faturamento.valor', 1430)
            ->assertJsonPath('pedidos.valor', 5)
            ->assertJsonPath('faturamento.anterior', 25)
            ->assertJsonCount(19, 'serie')
            ->assertJsonPath('serie.0.rotulo', '01/11')
            ->assertJsonPath('serie.0.pedidos', 1);
    }

    public function test_gasto_e_roas_da_meta_vem_da_conta_da_loja(): void
    {
        config([
            'services.meta_ads.access_token' => 'tk',
            'services.meta_ads.ad_account_id' => '111',
            'services.meta_ads.page_id' => '9',
            'services.meta_ads.ferrakora_ad_account_id' => '222',
        ]);
        Http::fake(function (HttpRequest $req) {
            $kz = str_contains($req->url(), 'act_111');

            return Http::response(['data' => [
                ['date_start' => '2026-11-19', 'spend' => $kz ? '50.00' : '20.00', 'impressions' => '1', 'clicks' => '1'],
                ['date_start' => '2026-11-15', 'spend' => $kz ? '30.00' : '10.00', 'impressions' => '1', 'clicks' => '1'],
                ['date_start' => '2026-11-10', 'spend' => $kz ? '100.00' : '5.00', 'impressions' => '1', 'clicks' => '1'],
            ]]);
        });
        $this->cenario();

        $this->api($this->admin(), 'kazakora')->getJson('/api-apps-vendas/kazakora/metricas?periodo=7d')->assertOk()
            ->assertJsonPath('meta.configurada', true)
            ->assertJsonPath('meta.gasto.valor', 80)
            ->assertJsonPath('meta.gasto.anterior', 100)
            ->assertJsonPath('meta.roas.valor', 17.38); // 1.390 / 80

        $fk = $this->api($this->admin(), 'ferrakora')->getJson('/api-apps-vendas/ferrakora/metricas?periodo=7d')->assertOk()
            ->assertJsonPath('meta.gasto.valor', 30)
            ->assertJsonPath('meta.roas.valor', 10);

        // Conta Meta da FerraKora fora da conta de lucro: lucro omitido, com o motivo.
        $this->assertNull($fk->json('lucro.valor'));
        $this->assertNotEmpty($fk->json('lucro.motivo'));

        Cache::flush();
        ChannelAdSpend::create(['date' => '2026-11-19', 'channel' => 'ferrakora', 'spend' => 20]);
        $this->api($this->admin(), 'ferrakora')->getJson('/api-apps-vendas/ferrakora/metricas?periodo=7d')->assertOk()
            ->assertJsonPath('lucro.motivo', null);
    }

    public function test_so_admin_com_token_do_proprio_app(): void
    {
        $this->getJson('/api-apps-vendas/kazakora/metricas')->assertUnauthorized();

        $gerente = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $this->api($gerente, 'kazakora')->getJson('/api-apps-vendas/kazakora/metricas')->assertUnauthorized();

        // Token do app KazaKora não abre o app FerraKora.
        $admin = $this->admin();
        $this->api($admin, 'kazakora')->getJson('/api-apps-vendas/ferrakora/metricas')->assertUnauthorized();

        // Token vencido não vale; admin rebaixado perde o acesso na hora.
        $token = TokensDoApp::emitir($admin, 'kazakora')['token'];
        $this->withToken($token)->getJson('/api-apps-vendas/kazakora/sessao')->assertOk();
        Carbon::setTestNow($this->agora->copy()->addDays(TokensDoApp::DIAS + 1));
        $this->withToken($token)->getJson('/api-apps-vendas/kazakora/sessao')->assertUnauthorized();
        Carbon::setTestNow($this->agora);
        $outro = TokensDoApp::emitir($admin, 'kazakora')['token'];
        $admin->forceFill(['role' => User::ROLE_MANAGER])->save();
        $this->withToken($outro)->getJson('/api-apps-vendas/kazakora/sessao')->assertUnauthorized();

        $this->api($this->admin(), 'kazakora')->getJson('/api-apps-vendas/beautykora/metricas')->assertNotFound();
        $this->api($this->admin(), 'kazakora')->getJson('/api-apps-vendas/kazakora/metricas?periodo=qualquer')
            ->assertOk()->assertJsonPath('periodo', 'mes');
    }

    // ---------- Setas do app: voltar no tempo com &fim= (2026-11-24) ----------

    public function test_mes_com_fim_mostra_os_30_dias_ate_a_data_e_compara_com_os_30_anteriores(): void
    {
        $this->cenario();
        $this->pedido(Order::ORIGIN_STORE, null, Order::STATUS_PAID, 40.00, '2026-11-12 23:00');
        $this->pedido(Order::ORIGIN_STORE, null, Order::STATUS_PAID, 15.00, '2026-10-14 00:30');
        $this->pedido(Order::ORIGIN_STORE, null, Order::STATUS_PAID, 700.00, '2026-11-13 08:00'); // depois do fim: fora
        $this->pedido(Order::ORIGIN_STORE, null, Order::STATUS_PAID, 30.00, '2026-10-13 22:00'); // anterior
        $this->pedido(Order::ORIGIN_STORE, null, Order::STATUS_PAID, 20.00, '2026-09-14 10:00'); // anterior
        $this->pedido(Order::ORIGIN_STORE, null, Order::STATUS_PAID, 900.00, '2026-09-13 10:00'); // antes do anterior: fora
        $this->visita('kazakora', 'kz-out', '2026-11-01 10:00');
        $this->visita('kazakora', 'kz-set', '2026-09-20 10:00');
        $admin = $this->admin();

        // O atual continua o mesmo e não se mistura com a janela deslocada no cache.
        $this->api($admin, 'kazakora')->getJson('/api-apps-vendas/kazakora/metricas?periodo=mes')->assertOk()
            ->assertJsonPath('deslocado', false)->assertJsonPath('fim', '2026-11-19')->assertJsonCount(19, 'serie');

        $this->getJson('/api-apps-vendas/kazakora/metricas?periodo=mes&fim=2026-11-12')->assertOk()
            ->assertJsonPath('periodo', 'mes')
            ->assertJsonPath('deslocado', true)
            ->assertJsonPath('fim', '2026-11-12')
            ->assertJsonPath('de', '2026-10-14T00:00:00-03:00')
            ->assertJsonPath('ate', '2026-11-12T23:59:59-03:00')
            ->assertJsonPath('faturamento.valor', 55)
            ->assertJsonPath('pedidos.valor', 2)
            ->assertJsonPath('faturamento.anterior', 50)
            ->assertJsonPath('faturamento.variacao', 10)
            ->assertJsonPath('visitas.valor', 1)
            ->assertJsonPath('visitas.anterior', 1)
            ->assertJsonCount(30, 'serie')
            ->assertJsonPath('serie.0.rotulo', '14/10')
            ->assertJsonPath('serie.29.rotulo', '12/11')
            ->assertJsonPath('serie.29.pedidos', 1);
    }

    public function test_semana_e_hoje_com_fim_comparam_janelas_inteiras_de_mesmo_tamanho(): void
    {
        $this->cenario();
        $this->pedido(Order::ORIGIN_STORE, null, Order::STATUS_PAID, 60.00, '2026-11-06 00:10');
        $this->pedido(Order::ORIGIN_STORE, null, Order::STATUS_PAID, 30.00, '2026-10-30 12:00');
        $this->pedido(Order::ORIGIN_STORE, null, Order::STATUS_PAID, 50.00, '2026-11-17 12:00');
        $admin = $this->admin();

        $this->api($admin, 'kazakora')->getJson('/api-apps-vendas/kazakora/metricas?periodo=7d&fim=2026-11-12')->assertOk()
            ->assertJsonPath('de', '2026-11-06T00:00:00-03:00')
            ->assertJsonPath('faturamento.valor', 60)
            ->assertJsonPath('faturamento.anterior', 30)
            ->assertJsonCount(7, 'serie')
            ->assertJsonPath('serie.6.rotulo', '12/11');

        // Ontem inteiro (inclusive o pedido das 16h, que o "hoje" até as 15h não pega) × anteontem inteiro.
        $this->getJson('/api-apps-vendas/kazakora/metricas?periodo=hoje&fim=2026-11-18')->assertOk()
            ->assertJsonPath('deslocado', true)
            ->assertJsonPath('faturamento.valor', 1080)
            ->assertJsonPath('pedidos.valor', 2)
            ->assertJsonPath('faturamento.anterior', 50)
            ->assertJsonPath('visitas.valor', 1)
            ->assertJsonCount(24, 'serie')
            ->assertJsonPath('serie.23.rotulo', '23h');
    }

    public function test_fim_valida_data_e_hoje_e_o_mesmo_que_sem_fim(): void
    {
        $this->cenario();
        $admin = $this->admin();

        $this->api($admin, 'kazakora')->getJson('/api-apps-vendas/kazakora/metricas?periodo=7d&fim=2026-11-20')
            ->assertStatus(422)->assertJsonValidationErrors('fim');
        $this->getJson('/api-apps-vendas/kazakora/metricas?periodo=7d&fim=ontem')->assertStatus(422);
        $this->getJson('/api-apps-vendas/kazakora/metricas?periodo=7d&fim=2026-02-30')->assertStatus(422);

        $sem = $this->getJson('/api-apps-vendas/kazakora/metricas?periodo=7d')->assertOk()->json();
        $hoje = $this->getJson('/api-apps-vendas/kazakora/metricas?periodo=7d&fim=2026-11-19')->assertOk()->json();
        $this->assertSame($sem, $hoje);
        $this->assertFalse($hoje['deslocado']);
        $this->assertSame('2026-11-19T15:00:00-03:00', $hoje['ate']);
    }

    public function test_primeiro_registro_e_o_primeiro_pedido_ou_visita_da_propria_loja(): void
    {
        $this->cenario();
        $this->pedido(Order::ORIGIN_MERCADO_LIVRE, null, Order::STATUS_PAID, 10.00, '2025-01-05 10:00'); // marketplace: não conta
        $this->pedido(Order::ORIGIN_STORE, null, Order::STATUS_CANCELLED, 10.00, '2026-04-10 10:00');
        $this->visita('kazakora', 'kz-antigo', '2026-03-02 09:00');
        $this->pedido(Order::ORIGIN_STORE, Order::SITE_FERRAKORA, Order::STATUS_PAID, 10.00, '2026-08-20 10:00');
        $admin = $this->admin();

        $this->api($admin, 'kazakora')->getJson('/api-apps-vendas/kazakora/metricas')->assertOk()
            ->assertJsonPath('primeiro_registro', '2026-03-02');
        $this->api($admin, 'ferrakora')->getJson('/api-apps-vendas/ferrakora/metricas?periodo=7d&fim=2026-10-01')->assertOk()
            ->assertJsonPath('primeiro_registro', '2026-08-20');
    }

    public function test_cors_so_para_os_sites_dos_apps(): void
    {
        $this->call('OPTIONS', '/api-apps-vendas/kazakora/metricas', [], [], [], [
            'HTTP_ORIGIN' => 'https://app.kazakora.com', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'https://app.kazakora.com');

        $this->call('OPTIONS', '/api-apps-vendas/kazakora/login', [], [], [], [
            'HTTP_ORIGIN' => 'https://app.ferrakora.com', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'https://app.ferrakora.com');

        $estranho = $this->call('OPTIONS', '/api-apps-vendas/kazakora/metricas', [], [], [], [
            'HTTP_ORIGIN' => 'https://golpe.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);
        $this->assertFalse($estranho->headers->has('Access-Control-Allow-Origin'));

        $this->api($this->admin(), 'kazakora')->getJson('/api-apps-vendas/kazakora/sessao', ['Origin' => 'https://app.kazakora.com'])
            ->assertOk()->assertHeader('Access-Control-Allow-Origin', 'https://app.kazakora.com');
    }

    public function test_resposta_fica_guardada_60_segundos(): void
    {
        $this->cenario();
        $admin = $this->admin();

        $url = '/api-apps-vendas/kazakora/metricas?periodo=hoje';
        $this->api($admin, 'kazakora')->getJson($url)->assertJsonPath('pedidos.valor', 2);
        $this->pedido(Order::ORIGIN_STORE, null, Order::STATUS_PAID, 10.00, '2026-11-19 14:30');
        $this->getJson($url)->assertJsonPath('pedidos.valor', 2);

        Carbon::setTestNow($this->agora->copy()->addSeconds(61));
        $this->getJson($url)->assertJsonPath('pedidos.valor', 3);
    }

    // ---------- Telas do app: login, pedidos, produtos, campanhas ----------

    public function test_login_do_app_devolve_token_so_para_admin(): void
    {
        User::factory()->create(['email' => 'lira@teste.com', 'password' => Hash::make('segredo123'), 'role' => User::ROLE_ADMIN, 'name' => 'Lira Teste']);
        User::factory()->create(['email' => 'gerente@teste.com', 'password' => Hash::make('segredo123'), 'role' => User::ROLE_MANAGER]);

        $this->postJson('/api-apps-vendas/ferrakora/login', ['email' => 'lira@teste.com', 'password' => 'errada'])->assertStatus(422);
        $this->postJson('/api-apps-vendas/ferrakora/login', ['email' => 'gerente@teste.com', 'password' => 'segredo123'])->assertForbidden();

        $r = $this->postJson('/api-apps-vendas/ferrakora/login', ['email' => 'lira@teste.com', 'password' => 'segredo123', 'aparelho' => 'iPhone'])
            ->assertOk()->assertJsonPath('usuario', 'Lira');
        $token = (string) $r->json('token');
        $this->assertNotSame('', $token);
        $this->assertGuest();

        $this->withToken($token)->getJson('/api-apps-vendas/ferrakora/sessao')->assertOk()->assertJson(['logado' => true, 'usuario' => 'Lira']);
        $this->withToken($token)->postJson('/api-apps-vendas/ferrakora/sair')->assertOk();
        $this->withToken($token)->getJson('/api-apps-vendas/ferrakora/sessao')->assertUnauthorized();

        // 5 senhas erradas travam o e-mail.
        foreach (range(1, 5) as $i) {
            $this->postJson('/api-apps-vendas/kazakora/login', ['email' => 'lira@teste.com', 'password' => 'x'.$i]);
        }
        $this->postJson('/api-apps-vendas/kazakora/login', ['email' => 'lira@teste.com', 'password' => 'segredo123'])->assertStatus(429);
    }

    public function test_inscricao_de_aviso_pelo_token(): void
    {
        config(['services.webpush.public_key' => \App\Modules\Notifications\Push\WebPush::gerarChaves()['publica']]);
        $admin = $this->admin();
        $inscricao = ['endpoint' => 'https://web.push.apple.com/fk-token', 'keys' => [
            'p256dh' => \App\Modules\Notifications\Push\WebPush::gerarChaves()['publica'],
            'auth' => \App\Modules\Notifications\Push\WebPush::base64urlEncode(random_bytes(16)),
        ]];

        $this->api($admin, 'ferrakora')->postJson('/api-apps-vendas/ferrakora/inscrever', $inscricao)->assertOk();
        $this->assertDatabaseHas('push_inscricoes', ['app' => 'ferrakora', 'user_id' => $admin->id]);
        $this->getJson('/api-apps-vendas/ferrakora/estado')->assertOk()->assertJsonPath('aparelhos', 1);

        // Sair com o endpoint apaga a inscrição deste aparelho.
        $this->postJson('/api-apps-vendas/ferrakora/sair', ['endpoint' => $inscricao['endpoint']])->assertOk();
        $this->assertDatabaseCount('push_inscricoes', 0);
    }

    public function test_pedidos_e_detalhe_so_da_loja_do_app_sem_dado_pessoal(): void
    {
        $p = $this->cenario();
        $admin = $this->admin();

        $lista = $this->api($admin, 'kazakora')->getJson('/api-apps-vendas/kazakora/pedidos')->assertOk();
        $this->assertEqualsCanonicalizing(
            [$p['kz1']->id, $p['kz2']->id, $p['kzPix']->id, $p['kzCancelado']->id, $p['kzOntem']->id, $p['kzOntemTarde']->id],
            collect($lista->json('pedidos'))->pluck('id')->all(),
        );
        $this->api($admin, 'kazakora')->getJson('/api-apps-vendas/kazakora/pedidos?status=aguardando')
            ->assertJsonCount(1, 'pedidos')->assertJsonPath('pedidos.0.id', $p['kzPix']->id);

        $detalhe = $this->api($admin, 'kazakora')->getJson('/api-apps-vendas/kazakora/pedidos/'.$p['kz1']->id)->assertOk()
            ->assertJsonPath('total', 110)
            ->assertJsonPath('cliente', 'Maria')
            ->assertJsonPath('cidade', 'São Paulo / SP')
            ->assertJsonPath('itens.0.nome', 'Árvore de Natal')
            ->assertJsonPath('pagamento.metodo', 'Cartão');
        foreach (['Sigilosa', 'Secreta', '11988887777', '01310'] as $pessoal) {
            $this->assertStringNotContainsString($pessoal, $detalhe->getContent());
        }

        // Pedido de outra loja ou de marketplace não existe no app.
        $this->api($admin, 'kazakora')->getJson('/api-apps-vendas/kazakora/pedidos/'.$p['fk']->id)->assertNotFound();
        $this->api($admin, 'kazakora')->getJson('/api-apps-vendas/kazakora/pedidos/'.$p['ml']->id)->assertNotFound();
        $this->api($admin, 'ferrakora')->getJson('/api-apps-vendas/ferrakora/pedidos/'.$p['kz1']->id)->assertNotFound();
        $this->api($admin, 'ferrakora')->getJson('/api-apps-vendas/ferrakora/pedidos/'.$p['fk']->id)->assertOk();

        $gerente = User::factory()->create(['role' => User::ROLE_MANAGER]);
        $this->api($gerente, 'kazakora')->getJson('/api-apps-vendas/kazakora/pedidos')->assertUnauthorized();
    }

    public function test_produtos_do_catalogo_de_cada_loja(): void
    {
        $kz = Product::factory()->create(['name' => 'Árvore de Natal 1,80', 'is_active' => true, 'so_ferrakora' => false]);
        $soFk = Product::factory()->create(['name' => 'Trena 5m', 'is_active' => true, 'so_ferrakora' => true]);
        $ambos = Product::factory()->create(['name' => 'Parafusadeira 48V', 'is_active' => true, 'so_ferrakora' => false]);
        FerraKoraProduto::create(['product_id' => $soFk->id, 'titulo' => 'Trena Profissional 5m', 'ordem' => 1, 'ativo' => true]);
        FerraKoraProduto::create(['product_id' => $ambos->id, 'ordem' => 2, 'ativo' => false]);
        $admin = $this->admin();

        $kzIds = collect($this->api($admin, 'kazakora')->getJson('/api-apps-vendas/kazakora/produtos')->assertOk()->json('produtos'))->pluck('id');
        $this->assertTrue($kzIds->contains($kz->id));
        $this->assertFalse($kzIds->contains($soFk->id));

        $fk = collect($this->api($admin, 'ferrakora')->getJson('/api-apps-vendas/ferrakora/produtos')->assertOk()->json('produtos'))->keyBy('id');
        $this->assertEqualsCanonicalizing([$soFk->id, $ambos->id], $fk->keys()->all());
        $this->assertSame('Trena Profissional 5m', $fk[$soFk->id]['nome']);
        $this->assertFalse($fk[$ambos->id]['ativo'], 'despublicado no site FerraKora');

        $this->api($admin, 'kazakora')->getJson('/api-apps-vendas/kazakora/produtos?busca=Árvore')->assertJsonCount(1, 'produtos');
    }

    public function test_campanhas_sem_meta_configurada(): void
    {
        $this->api($this->admin(), 'ferrakora')->getJson('/api-apps-vendas/ferrakora/campanhas')->assertOk()
            ->assertJson(['configurada' => false, 'campanhas' => []]);
    }
}
