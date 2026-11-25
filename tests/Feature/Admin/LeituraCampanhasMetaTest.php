<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Admin\Support\Publicidade\CpaDoProduto;
use App\Modules\Admin\Support\Publicidade\LeituraCampanhasMeta;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Checkout\Support\FreteEmbutido;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Leitura das campanhas (2026-11-25): CPA alvo/máximo, semáforo, "O que
 * fazer", ciclo e permissão, com a Graph API falsa.
 */
class LeituraCampanhasMetaTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $pedidosNaMeta = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'inertia.testing.ensure_pages_exist' => false,
            'services.meta_ads.access_token' => 'token-kz',
            'services.meta_ads.ad_account_id' => 'act_123',
            'services.meta_ads.page_id' => '999',
            'services.meta_ads.graph_url' => 'https://graph.facebook.com/v23.0',
            'loja.simples.aliquota' => 4.5,
            'loja.insumos_por_pedido' => 2.0,
            'leitura_meta.taxa_pagamento' => 4.98,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-11-27 12:00:00', 'America/Sao_Paulo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function produto(): Product
    {
        return Product::factory()->create(['slug' => 'travesseiro-nuvem', 'price' => 149.90, 'cost_price' => 40, 'is_active' => true]);
    }

    /** Venda do site com etiqueta paga (frete real). */
    private function venda(Product $produto, float $subtotal, float $frete): void
    {
        $order = Order::create([
            'status' => Order::STATUS_PAID, 'origin' => 'loja',
            'shipping_name' => 'Cliente', 'shipping_phone' => '85999999999', 'shipping_zip' => '60025-000',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'Fortaleza', 'shipping_state' => 'CE',
            'subtotal' => $subtotal, 'shipping_cost' => 0, 'shipping_carrier_name' => 'PAC', 'total' => $subtotal,
        ]);
        $order->items()->create(['product_id' => $produto->id, 'product_name' => $produto->name, 'product_price' => $subtotal, 'quantity' => 1, 'subtotal' => $subtotal]);
        CorreiosPrePostagem::create([
            'order_id' => $order->id, 'status' => CorreiosPrePostagem::STATUS_GERADA, 'postage_price' => $frete,
            'customer_name' => 'Cliente', 'zip' => '60025000', 'street' => 'Rua X', 'number' => '1',
            'neighborhood' => 'Centro', 'city' => 'Fortaleza', 'state' => 'CE',
            'service_code' => FreteEmbutido::PAC, 'weight_grams' => 300, 'content_items' => [],
        ]);
    }

    // CPA ----------------------------------------------------------------------

    public function test_cpa_maximo_e_alvo_saem_das_vendas_reais_do_produto(): void
    {
        $produto = $this->produto();
        $this->venda($produto, 149.90, 20);
        $this->venda($produto, 149.90, 22);

        $cpa = app(CpaDoProduto::class)->doProduto($produto->id);

        // 149,90 − 40 (custo) − 21 (frete médio) − 4,5% − 2 (insumos) − 4,98%.
        $maximo = round(149.90 - 40 - 21 - 149.90 * 0.045 - 2 - 149.90 * 0.0498, 2);
        $this->assertSame(2, $cpa['pedidos']);
        $this->assertSame(149.9, $cpa['preco']);
        $this->assertSame(21.0, $cpa['frete']);
        $this->assertSame($maximo, $cpa['cpa_maximo']);
        $this->assertSame(round($maximo / 2, 2), $cpa['cpa_alvo']);
        $this->assertFalse($cpa['manual']);
    }

    public function test_cpa_digitado_na_tela_vale_no_lugar_do_calculado(): void
    {
        $produto = $this->produto();
        $this->venda($produto, 149.90, 20);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->post('/admin/publicidade/meta-ads/leitura/cpa', ['product_id' => $produto->id, 'cpa_alvo' => 30, 'cpa_maximo' => 65])
            ->assertRedirect();

        $cpa = app(CpaDoProduto::class)->doProduto($produto->id);
        $this->assertSame(30.0, $cpa['cpa_alvo']);
        $this->assertSame(65.0, $cpa['cpa_maximo']);
        $this->assertTrue($cpa['manual']);
    }

    public function test_produto_sai_do_link_do_anuncio(): void
    {
        $produto = $this->produto();

        $this->assertSame($produto->id, CpaDoProduto::produtoDoLink('https://kazakora.com/produtos/travesseiro-nuvem?utm_source=meta'));
        $this->assertNull(CpaDoProduto::produtoDoLink('https://kazakora.com/'));
    }

    // Semáforo -----------------------------------------------------------------

    public function test_semaforo_nas_faixas(): void
    {
        $cor = fn (string $m, $v) => LeituraCampanhasMeta::semaforo($m, $v)['cor'] ?? null;

        // Hook: ≥30 verde, 20–30 amarelo, <20 vermelho.
        $this->assertSame('verde', $cor('hook', 30));
        $this->assertSame('amarelo', $cor('hook', 20));
        $this->assertSame('vermelho', $cor('hook', 19.9));
        // CPM: ≤35 verde, até 50 amarelo, >50 vermelho.
        $this->assertSame('verde', $cor('cpm', 35));
        $this->assertSame('amarelo', $cor('cpm', 50));
        $this->assertSame('vermelho', $cor('cpm', 50.01));
        // CTR: ≥2 verde, 1–2 amarelo, <1 vermelho.
        $this->assertSame('verde', $cor('ctr', 2));
        $this->assertSame('amarelo', $cor('ctr', 1));
        $this->assertSame('vermelho', $cor('ctr', 0.99));
        // CPC: ≤2 verde, até 3 amarelo, >3 vermelho.
        $this->assertSame('verde', $cor('cpc', 2));
        $this->assertSame('amarelo', $cor('cpc', 3));
        $this->assertSame('vermelho', $cor('cpc', 3.01));
        // Frequência: ≤2,5 verde, até 3,5 amarelo, >3,5 vermelho.
        $this->assertSame('verde', $cor('frequencia', 2.5));
        $this->assertSame('amarelo', $cor('frequencia', 3.5));
        $this->assertSame('vermelho', $cor('frequencia', 3.6));
        // Sem dado (anúncio sem vídeo): sem cor.
        $this->assertNull(LeituraCampanhasMeta::semaforo('hook', null));
        // CPA: até o alvo verde, até o máximo amarelo, acima vermelho.
        $this->assertSame('verde', LeituraCampanhasMeta::semaforoCpa(35, 35, 71)['cor']);
        $this->assertSame('amarelo', LeituraCampanhasMeta::semaforoCpa(71, 35, 71)['cor']);
        $this->assertSame('vermelho', LeituraCampanhasMeta::semaforoCpa(71.5, 35, 71)['cor']);
    }

    // O que fazer --------------------------------------------------------------

    /** Números do período, todos bons por padrão. */
    private static function m(array $troca = []): array
    {
        return array_merge([
            'hook' => 35.0, 'frequencia' => 1.5, 'cpm' => 25.0, 'ctr_link' => 2.5, 'cpc_link' => 1.2, 'cliques_link' => 50,
            'visualizacoes_pagina' => 45, 'carrinho' => 3, 'checkout' => 2, 'compras' => 1,
        ], $troca);
    }

    private static function ciclo(array $troca = []): array
    {
        return array_merge(['gasto' => 40.0, 'compras' => 1, 'carrinho' => 3, 'gasto_sem_venda' => 10.0, 'ultimos2_gasto' => 40.0, 'ultimos2_compras' => 0], $troca);
    }

    private const CPA = ['alvo' => 35.0, 'maximo' => 71.0];

    private const ORCAMENTO = ['valor' => 50.0, 'nivel' => 'conjunto'];

    public function test_o_que_fazer_desligar(): void
    {
        $a = LeituraCampanhasMeta::oQueFazer(self::m(['compras' => 0]), self::ciclo(['gasto' => 80, 'compras' => 0, 'gasto_sem_venda' => 80]), self::CPA, self::ORCAMENTO);

        $this->assertSame('vermelho', $a['nivel']);
        $this->assertSame('🔴 Gastou R$ 80,00 sem vender (o seu 0 a 0 é R$ 71,00). Desligue o conjunto.', $a['frase']);
    }

    public function test_o_que_fazer_fraco_baixa_20_por_cento(): void
    {
        $a = LeituraCampanhasMeta::oQueFazer(self::m(['carrinho' => 0, 'checkout' => 0, 'compras' => 0]),
            self::ciclo(['gasto' => 40, 'compras' => 0, 'carrinho' => 0, 'gasto_sem_venda' => 40]), self::CPA, self::ORCAMENTO);

        $this->assertSame('amarelo', $a['nivel']);
        $this->assertSame('🟡 Fraco: baixe 20% (de R$ 50,00 para R$ 40,00), não desligue.', $a['frase']);
    }

    public function test_o_que_fazer_subir_20_por_cento(): void
    {
        $a = LeituraCampanhasMeta::oQueFazer(self::m(), self::ciclo(['ultimos2_gasto' => 60, 'ultimos2_compras' => 2]), self::CPA,
            ['valor' => 100.0, 'nivel' => 'campanha']);

        $this->assertSame('verde', $a['nivel']);
        $this->assertSame('🟢 Pode subir 20% (de R$ 100,00 para R$ 120,00, orçamento da campanha), entre 0 h e 10 h, e espere 2 dias.', $a['frase']);
    }

    public function test_o_que_fazer_ainda_cedo(): void
    {
        $a = LeituraCampanhasMeta::oQueFazer(self::m(['compras' => 0, 'carrinho' => 0, 'checkout' => 0]),
            self::ciclo(['gasto' => 12, 'compras' => 0, 'carrinho' => 0, 'gasto_sem_venda' => 12]), self::CPA, self::ORCAMENTO);

        $this->assertSame('espera', $a['nivel']);
        $this->assertSame('⏳ Ainda cedo: espere gastar R$ 35,00 antes de julgar.', $a['frase']);
    }

    public function test_o_que_fazer_hook_baixo_e_ordem_do_funil(): void
    {
        // Hook e CTR ruins: fala do hook (vem antes na ordem de leitura).
        $a = LeituraCampanhasMeta::oQueFazer(self::m(['hook' => 12.0, 'ctr_link' => 0.5, 'compras' => 0]),
            self::ciclo(['gasto' => 50, 'compras' => 0, 'gasto_sem_venda' => 50]), self::CPA, self::ORCAMENTO);
        $this->assertSame('amarelo', $a['nivel']);
        $this->assertStringContainsString('troque o começo do vídeo', mb_strtolower($a['frase']));

        // Sem vídeo (hook null), CTR ruim.
        $b = LeituraCampanhasMeta::oQueFazer(self::m(['hook' => null, 'ctr_link' => 0.5, 'compras' => 0]),
            self::ciclo(['gasto' => 50, 'compras' => 0, 'gasto_sem_venda' => 50]), self::CPA, self::ORCAMENTO);
        $this->assertStringContainsString('o anúncio não convence a clicar: teste outro gancho/texto', $b['frase']);

        // Carrinho sem compra.
        $c = LeituraCampanhasMeta::oQueFazer(self::m(['checkout' => 0, 'compras' => 0]),
            self::ciclo(['gasto' => 50, 'compras' => 0, 'gasto_sem_venda' => 50]), self::CPA, self::ORCAMENTO);
        $this->assertStringContainsString('olhe a página, o preço e o frete', $c['frase']);
    }

    public function test_gasto_desde_a_ultima_venda_e_ultimos_dois_dias(): void
    {
        $dias = [
            ['data' => '2026-11-24', 'gasto' => 30.0, 'compras' => 1, 'carrinho' => 1], // antes do ciclo
            ['data' => '2026-11-25', 'gasto' => 20.0, 'compras' => 1, 'carrinho' => 2],
            ['data' => '2026-11-26', 'gasto' => 25.0, 'compras' => 0, 'carrinho' => 1],
            ['data' => '2026-11-27', 'gasto' => 15.0, 'compras' => 0, 'carrinho' => 0],
        ];

        $n = LeituraCampanhasMeta::numerosDoCiclo($dias, '2026-11-25 16:41');

        $this->assertSame(60.0, $n['gasto']);
        $this->assertSame(1, $n['compras']);
        $this->assertSame(40.0, $n['gasto_sem_venda']); // 26 + 27, depois do dia da venda
        $this->assertSame(45.0, $n['ultimos2_gasto']); // 25 e 26 (hoje é 27)
        $this->assertSame(1, $n['ultimos2_compras']);
    }

    // Tela e filtro do ciclo ---------------------------------------------------

    private function fingirMeta(Product $produto): void
    {
        Http::fake(function (HttpRequest $r) use ($produto) {
            $url = $r->url();
            $dados = $r->data();
            $this->pedidosNaMeta[] = ['url' => $url, 'dados' => $dados];

            return match (true) {
                str_contains($url, 'act_123/adsets') => Http::response(['data' => [[
                    'id' => '501', 'name' => 'Aberto 25-45', 'campaign_id' => '901', 'effective_status' => 'ACTIVE', 'daily_budget' => '5000',
                    'campaign' => ['name' => '[KZ] TRAVESSEIRO | Vendas', 'effective_status' => 'ACTIVE', 'start_time' => '2026-11-20T10:00:00-0300'],
                ]]]),
                str_contains($url, 'act_123/ads') => Http::response(['data' => [[
                    'id' => '7001', 'adset_id' => '501',
                    'creative' => ['object_story_spec' => ['video_data' => ['call_to_action' => ['type' => 'SHOP_NOW', 'value' => ['link' => "https://kazakora.com/produtos/{$produto->slug}"]]]]],
                ]]]),
                str_contains($url, 'act_123/insights') && isset($dados['time_increment']) => Http::response(['data' => [
                    ['adset_id' => '501', 'campaign_id' => '901', 'date_start' => '2026-11-25', 'spend' => '40', 'impressions' => '2000', 'clicks' => '40',
                        'actions' => [['action_type' => 'add_to_cart', 'value' => '2']]],
                    ['adset_id' => '501', 'campaign_id' => '901', 'date_start' => '2026-11-26', 'spend' => '45', 'impressions' => '2200', 'clicks' => '30', 'actions' => []],
                ]]),
                str_contains($url, 'act_123/insights') => Http::response(['data' => [[
                    'adset_id' => '501', 'campaign_id' => '901', 'spend' => '85', 'impressions' => '4200', 'reach' => '3000', 'clicks' => '70',
                    'frequency' => '1.4', 'cpm' => '20.24', 'inline_link_clicks' => '60', 'inline_link_click_ctr' => '1.43',
                    'actions' => [
                        ['action_type' => 'video_view', 'value' => '1050'],
                        ['action_type' => 'landing_page_view', 'value' => '50'],
                        ['action_type' => 'add_to_cart', 'value' => '2'],
                    ],
                ]]]),
                default => Http::response(['data' => []]),
            };
        });
    }

    public function test_filtro_desde_o_inicio_do_ciclo_usa_o_ciclo_do_travesseiro(): void
    {
        $produto = $this->produto();
        $this->venda($produto, 149.90, 21);
        $this->fingirMeta($produto);

        $d = app(LeituraCampanhasMeta::class)->montar('kazakora', 'ciclo', 'ativos');

        $this->assertNull($d['erro']);
        $l = $d['linhas'][0];
        // Ciclo da migration: nome com TRAVESSEIRO, 25/11 16:41.
        $this->assertSame('2026-11-25 16:41', $l['ciclo']['inicio']);
        $this->assertSame('nome', $l['ciclo']['origem']);
        $this->assertSame($produto->id, $l['produto']['product_id']);

        // O período do ciclo pede à Meta desde o dia do início do ciclo.
        $doPeriodo = collect($this->pedidosNaMeta)->first(fn ($p) => str_contains($p['url'], 'insights') && ! isset($p['dados']['time_increment']));
        $this->assertSame(['since' => '2026-11-25', 'until' => '2026-11-27'], json_decode($doPeriodo['dados']['time_range'], true));
        $this->assertStringContainsString('frequency', $doPeriodo['dados']['fields']);
        $this->assertStringContainsString('cpm', $doPeriodo['dados']['fields']);
        // Só os ativos.
        $adsets = collect($this->pedidosNaMeta)->first(fn ($p) => str_contains($p['url'], 'adsets'));
        $this->assertSame(['ACTIVE'], json_decode($adsets['dados']['effective_status'], true));

        // Números: hook = 1050 ÷ 4200; frequência e CPM da Meta.
        $this->assertSame(25.0, $l['m']['hook']);
        $this->assertSame('amarelo', $l['sem']['hook']['cor']);
        $this->assertSame(1.4, $l['m']['frequencia']);
        $this->assertSame(20.24, $l['m']['cpm']);
        $this->assertSame(50.0, $l['orcamento']['valor']);
        // 85 gastos sem vender, acima do 0 a 0 do travesseiro: desligar.
        $this->assertSame('vermelho', $l['acao']['nivel']);
        $this->assertStringContainsString('Gastou R$ 85,00 sem vender', $l['acao']['frase']);
        // Lucro sem venda = − gasto.
        $this->assertSame(-85.0, $l['m']['lucro']);
    }

    public function test_salvar_ciclo_da_campanha_muda_o_inicio(): void
    {
        $produto = $this->produto();
        $this->fingirMeta($produto);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->post('/admin/publicidade/meta-ads/leitura/ciclo', ['loja' => 'kazakora', 'campanha_id' => '901', 'inicio' => '2026-11-26T08:00'])
            ->assertRedirect();
        $this->assertDatabaseHas('leitura_meta_ciclos', ['campanha_id' => '901', 'inicio' => '2026-11-26 08:00:00']);

        $l = app(LeituraCampanhasMeta::class)->montar('kazakora', 'ciclo', 'ativos')['linhas'][0];
        $this->assertSame('2026-11-26 08:00', $l['ciclo']['inicio']);
        $this->assertSame('campanha', $l['ciclo']['origem']);
        // Ciclo só com o dia 26: 45 gastos.
        $this->assertSame(45.0, $l['ciclo']['gasto']);
    }

    // Permissão ----------------------------------------------------------------

    public function test_tela_exige_login_e_permissao(): void
    {
        $this->get('/admin/publicidade/meta-ads/leitura')->assertRedirect();

        $assinante = User::factory()->create(['role' => User::ROLE_SUBSCRIBER]);
        DB::table('role_permissions')->where('role', User::ROLE_SUBSCRIBER)->where('permission', 'financeiro.view')->update(['granted' => false]);
        $this->actingAs($assinante)->get('/admin/publicidade/meta-ads/leitura')->assertForbidden();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->get('/admin/publicidade/meta-ads/leitura?periodo=last_14d&loja=kazakora')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/MetaAds/Leitura')
                ->where('periodo', 'last_14d')->where('status', 'ativos')->where('loja', 'kazakora')
                ->has('periodos.ciclo')->where('metricaProfessor', false));
    }
}
