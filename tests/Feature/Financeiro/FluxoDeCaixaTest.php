<?php

namespace Tests\Feature\Financeiro;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Financeiro\Models\CashFlowEntry;
use App\Modules\Financeiro\Support\AlertasFinanceiros;
use App\Modules\Financeiro\Support\PrevisaoDeCaixa;
use App\Modules\Marketplace\Models\ChannelAdSpend;
use App\Modules\Marketplace\Models\OrderChannelFee;
use App\Modules\Marketplace\Support\ContributionMargin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Fluxo de Caixa (aprovado pelo Lira em 2026-10-19): todo número da tela
 * vem da conta oficial. A série soma o período, a aba Vendas fecha com
 * porCanal(), o filtro de canal e a comparação funcionam, período vazio
 * mostra zero e /admin leva cada um para a tela que pode ver.
 */
class FluxoDeCaixaTest extends TestCase
{
    use RefreshDatabase;

    private const CAMPOS = ['receita', 'custo_produtos', 'taxas', 'frete', 'ads', 'bonificacoes', 'margem', 'imposto', 'lucro'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['loja.simples.aliquota' => 4.5, 'loja.simples.desde' => '2026-09-01', 'loja.ads_alerta_percentual' => 6]);
        Carbon::setTestNow('2026-10-19 15:00:00');
    }

    private function pedido(string $origem, float $subtotal, string $quando, float $custo = 40, ?float $taxa = null, string $fonte = OrderChannelFee::SOURCE_API, array $extra = []): Order
    {
        $produto = Product::factory()->create(['price' => $subtotal, 'cost_price' => $custo, 'stock' => 10]);
        $pedido = Order::create([
            'status' => Order::STATUS_PAID, 'origin' => $origem,
            'shipping_name' => 'Maria', 'shipping_phone' => '11999990000', 'shipping_zip' => '03187040',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Mooca',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => $subtotal, 'discount_amount' => 0, 'shipping_cost' => 10, 'total' => $subtotal + 10,
            ...$extra,
        ]);
        $pedido->forceFill(['created_at' => $quando])->save();
        $pedido->items()->create(['product_id' => $produto->id, 'product_name' => "Item {$origem}", 'product_price' => $subtotal, 'quantity' => 1, 'subtotal' => $subtotal, 'item_type' => OrderItem::TYPE_PRODUCT]);
        if ($taxa !== null) {
            OrderChannelFee::create(['order_id' => $pedido->id, 'channel' => $origem, 'gross_amount' => $subtotal, 'fee_amount' => $taxa, 'source' => $fonte, 'computed_at' => now()]);
        }

        return $pedido;
    }

    private function cenario(): void
    {
        $this->pedido('shopee', 200, '2026-10-02 10:00:00', 80, 40);
        $this->pedido('shopee', 150, '2026-10-09 23:30:00', 60, 30);
        $this->pedido('mercado_livre', 300, '2026-10-05 08:00:00', 120, 50);
        $this->pedido('tiktok_shop', 100, '2026-10-12 12:00:00', 30, 20, OrderChannelFee::SOURCE_ESTIMATE);
        $this->pedido('amazon', 120, '2026-10-15 09:00:00', 50, 18);
        // Mês passado, para a comparação.
        $this->pedido('shopee', 500, '2026-09-04 10:00:00', 100, 60);
        // Cancelado não é venda.
        $this->pedido('shopee', 999, '2026-10-03 10:00:00', 1, 1, extra: ['status' => Order::STATUS_CANCELLED]);
        // Nota de venda menor que o subtotal: o imposto sai sobre ela.
        $ml = Order::query()->where('origin', 'mercado_livre')->first();
        DB::table('invoices')->insert(['order_id' => $ml->id, 'numero' => 7, 'status' => 'authorized', 'operation_type' => 'saida', 'valor_total' => 280, 'created_at' => now(), 'updated_at' => now()]);

        foreach ([['2026-10-02', 'shopee', 15], ['2026-10-10', 'shopee', 25], ['2026-10-06', 'mercado_livre', 9]] as [$dia, $canal, $gasto]) {
            ChannelAdSpend::create(['date' => $dia, 'channel' => $canal, 'spend' => $gasto, 'impressions' => 0, 'clicks' => 0, 'attributed_orders' => 0, 'attributed_gmv' => 0]);
        }
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_soma_da_serie_e_igual_a_conta_do_periodo(): void
    {
        $this->cenario();
        $margem = app(ContributionMargin::class);
        $desde = Carbon::parse('2026-10-01');
        $ate = Carbon::parse('2026-10-19');
        $periodo = $margem->periodo($desde, $ate);

        foreach (['dia', 'semana', 'mes'] as $agrupar) {
            $serie = $margem->serie($desde, $ate, $agrupar);
            foreach (self::CAMPOS as $campo) {
                $this->assertEqualsWithDelta($periodo[$campo], array_sum(array_column($serie, $campo)), 0.011, "{$agrupar}: {$campo}");
            }
        }
        $this->assertCount(18, $margem->serie($desde, $ate, 'dia'));
        $this->assertSame('2026-10-01', $margem->serie($desde, $ate, 'semana')[0]['inicio']);
        $this->assertSame('2026-10-05', $margem->serie($desde, $ate, 'semana')[1]['inicio'], 'semana começa na segunda');

        // Com canal: a série é a de porCanal().
        $shopee = $margem->porCanal($desde, $ate)['shopee'];
        $serie = $margem->serie($desde, $ate, 'dia', 'shopee');
        $this->assertEqualsWithDelta($shopee['lucro'], array_sum(array_column($serie, 'lucro')), 0.011);
        $this->assertEqualsWithDelta($shopee['ads'], array_sum(array_column($serie, 'ads')), 0.001);
    }

    public function test_imposto_e_base_vezes_aliquota_e_vendas_brutas_somam_o_total(): void
    {
        $this->cenario();
        $desde = Carbon::parse('2026-10-01');
        $ate = Carbon::parse('2026-10-19');

        $base = ContributionMargin::baseImposto($desde, $ate);
        $imposto = ContributionMargin::imposto($desde, $ate);
        $this->assertEqualsWithDelta(280.0, $base['mercado_livre'], 0.001, 'nota de R$ 280');
        $this->assertEqualsWithDelta(350.0, $base['shopee'], 0.001, 'sem nota: receita');
        foreach ($base as $canal => $valor) {
            $this->assertEqualsWithDelta(round($valor * 0.045, 2), $imposto[$canal], 0.001);
        }
        // Antes do Simples não há imposto, mas a base existe.
        $this->assertSame([], ContributionMargin::imposto(Carbon::parse('2026-08-01'), Carbon::parse('2026-09-01')));

        // 5 vendas de outubro, cada uma com R$ 10 de frete no total.
        $this->assertEqualsWithDelta(870.0 + 50, ContributionMargin::vendasBrutas($desde, $ate), 0.001);
        $this->assertEqualsWithDelta(370.0, ContributionMargin::vendasBrutas($desde, $ate, 'shopee'), 0.001);
    }

    public function test_resumo_usa_a_conta_oficial_com_comparacao_e_filtro_de_canal(): void
    {
        $this->cenario();
        $margem = app(ContributionMargin::class);
        $periodo = $margem->periodo(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-19'));
        $porCanal = $margem->porCanal(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-19'));
        $setembro = $margem->periodo(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-19'));
        $admin = $this->admin();
        $igual = fn (float $esperado) => fn ($valor) => abs($valor - $esperado) < 0.001;

        $this->actingAs($admin)->get('/admin/fluxo-de-caixa?de=2026-10-01&ate=2026-10-18')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filtro.aba', 'resumo')
                ->where('filtro.agrupar', 'dia')
                ->where('resumo.atual.lucro', $igual($periodo['lucro']))
                ->where('resumo.atual.margem', $igual($periodo['margem']))
                ->where('resumo.atual.imposto', $igual($periodo['imposto']))
                ->where('resumo.atual.pedidos', 5)
                ->where('resumo.comparacao.receita', $igual($setembro['receita']))
                ->where('filtro.compDe', '2026-09-01')
                ->where('filtro.compAte', '2026-09-18')
                ->has('serie', 18)
                ->where('canais', fn ($canais) => collect($canais)->every(fn ($c) => abs($c['lucro'] - ($porCanal[$c['canal']]['lucro'] ?? 0)) < 0.001))
                ->where('canais.0.lucro', fn ($lucro) => $lucro == max(array_column($porCanal, 'lucro')))
                ->where('qualidade.canais', fn ($q) => collect($q)->firstWhere('canal', 'tiktok_shop')['pedidos_estimada'] === 1)
                // Nenhuma aba alheia é calculada.
                ->missing('sales')
                ->missing('entries'));

        // Só a Shopee: a conta é a de porCanal()['shopee'].
        $this->actingAs($admin)->get('/admin/fluxo-de-caixa?de=2026-10-01&ate=2026-10-18&canal=shopee&comparar=nenhum')
            ->assertInertia(fn ($page) => $page
                ->where('filtro.canal', 'shopee')
                ->where('resumo.atual.lucro', $igual($porCanal['shopee']['lucro']))
                ->where('resumo.atual.ads', $igual(40))
                ->where('resumo.atual.pedidos', 2)
                ->where('resumo.comparacao', null)
                ->has('canais', 1)
                ->where('canais.0.canal', 'shopee'));

        // Período anterior: os mesmos 18 dias logo antes.
        $this->actingAs($admin)->get('/admin/fluxo-de-caixa?de=2026-10-01&ate=2026-10-18&comparar=anterior')
            ->assertInertia(fn ($page) => $page
                ->where('filtro.compDe', '2026-09-13')
                ->where('filtro.compAte', '2026-09-30'));
    }

    public function test_alertas_de_ads_alto_taxa_estimada_e_das_vencendo(): void
    {
        $this->cenario();

        $this->actingAs($this->admin())->get('/admin/fluxo-de-caixa?de=2026-10-01&ate=2026-10-18')
            ->assertInertia(fn ($page) => $page->where('alertas', function ($alertas) {
                $chaves = collect($alertas)->pluck('chave')->all();
                // ADS Shopee 40 ÷ 350 = 11,4% (> 6%); ML 9 ÷ 300 = 3% (ok).
                $this->assertContains('ads:shopee', $chaves);
                $this->assertNotContains('ads:mercado_livre', $chaves);
                // Taxa estimada do TikTok: 20 de 148 = 13,5% (> 10%).
                $this->assertContains('taxa-estimada', $chaves);
                // Hoje é 19/10: DAS de setembro vence amanhã (20/10, terça).
                $das = collect($alertas)->firstWhere('chave', 'das');
                $this->assertSame('VENCE AMANHÃ', $das['rotulo']);
                $this->assertStringContainsString('DAS de setembro vence 20/10', $das['texto']);
                $this->assertSame('/admin/notas-fiscais/fechamento', $das['links'][0]['href']);

                return true;
            }));
    }

    public function test_alertas_de_prejuizo_queda_sem_custo_e_nota_pendente(): void
    {
        $alertas = app(AlertasFinanceiros::class)->gerar([
            'hoje' => Carbon::parse('2026-10-05'),
            'rotuloPeriodo' => 'outubro',
            'das' => [],
            'canais' => ['shopee' => ['receita' => 100.0, 'ads' => 2.0, 'margem' => -5.0, 'lucro' => -9.5]],
            'receita' => 100.0,
            'receitaComparacao' => 200.0,
            'rotuloComparacao' => 'mesmo período do mês passado',
            'qualidade' => [],
            'semCusto' => 2,
            'linkSemCusto' => '/admin/fluxo-de-caixa?aba=vendas&sem_custo=1',
            'notasCanceladas' => ['quantidade' => 1, 'valor' => 59.9],
        ]);
        $porChave = collect($alertas)->keyBy('chave');

        $this->assertSame('PREJUÍZO', $porChave['prejuizo:shopee']['rotulo']);
        $this->assertSame('Shopee fechou o período com lucro de R$ -9,50.', $porChave['prejuizo:shopee']['texto']);
        $this->assertSame('/admin/oportunidades/shopee', $porChave['prejuizo:shopee']['links'][0]['href']);
        $this->assertSame('Receita 50,0% abaixo de mesmo período do mês passado.', $porChave['queda']['texto']);
        $this->assertSame('/admin/fluxo-de-caixa?aba=vendas&sem_custo=1', $porChave['sem-custo']['links'][0]['href']);
        $this->assertStringContainsString('1 nota de pedido cancelado ainda autorizada (R$ 59,90)', $porChave['nota-pendente']['texto']);
        $this->assertArrayNotHasKey('das', $porChave->all(), 'dia 5: fora da janela do DAS');
    }

    public function test_periodo_vazio_mostra_zero(): void
    {
        $this->actingAs($this->admin())->get('/admin/fluxo-de-caixa?de=2026-07-01&ate=2026-07-31&agrupar=semana')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('resumo.atual.lucro', 0)
                ->where('resumo.atual.receita', 0)
                ->where('resumo.atual.vendas_brutas', 0)
                ->where('resumo.atual.pedidos', 0)
                ->where('filtro.agrupar', 'semana')
                ->where('serie', fn ($serie) => collect($serie)->sum('receita') == 0 && count($serie) === 5)
                ->where('canais', fn ($canais) => count($canais) === 5 && collect($canais)->sum('lucro') == 0));

        $this->actingAs($this->admin())->get('/admin/fluxo-de-caixa?aba=vendas&de=2026-07-01&ate=2026-07-31')
            ->assertInertia(fn ($page) => $page
                ->has('sales.data', 0)
                ->where('salesTotals.netProfit', 0)
                ->where('salesTotals.margemOficial', 0)
                ->missing('resumo'));
    }

    public function test_aba_vendas_fecha_com_por_canal(): void
    {
        $this->cenario();
        $porCanal = app(ContributionMargin::class)->porCanal(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-19'));

        foreach ($porCanal as $canal => $conta) {
            $this->actingAs($this->admin())->get("/admin/fluxo-de-caixa?aba=vendas&de=2026-10-01&ate=2026-10-18&canal={$canal}")
                ->assertOk()
                ->assertInertia(fn ($page) => $page
                    ->where('salesTotals.margemOficial', fn ($m) => abs($m - $conta['margem']) < 0.011)
                    ->where('salesTotals.receita', fn ($r) => abs($r - $conta['receita']) < 0.011)
                    ->where('salesTotals.fee', fn ($t) => abs($t - $conta['taxas']) < 0.011));
        }

        $this->actingAs($this->admin())->get('/admin/fluxo-de-caixa?aba=vendas&de=2026-10-01&ate=2026-10-18&taxa_estimada=1')
            ->assertInertia(fn ($page) => $page
                ->has('sales.data', 1)
                ->where('sales.data.0.canal', 'tiktok_shop')
                ->where('sales.data.0.fee_source', 'estimate'));
    }

    public function test_previsao_traz_o_das_e_as_saidas_lancadas(): void
    {
        $this->cenario();
        $admin = $this->admin();
        CashFlowEntry::create(['type' => CashFlowEntry::TYPE_EXPENSE, 'description' => 'Contador', 'amount' => 350, 'entry_date' => '2026-10-25', 'created_by' => $admin->id]);
        CashFlowEntry::create(['type' => CashFlowEntry::TYPE_EXPENSE, 'description' => 'Muito longe', 'amount' => 99, 'entry_date' => '2026-12-25', 'created_by' => $admin->id]);

        $previsao = app(PrevisaoDeCaixa::class)->proximosDias();
        $titulos = collect($previsao['itens'])->pluck('titulo')->all();

        $this->assertContains('DAS de setembro', $titulos);
        $this->assertContains('DAS de outubro (parcial)', $titulos);
        $this->assertContains('Contador', $titulos);
        $this->assertNotContains('Muito longe', $titulos);
        $das = collect($previsao['itens'])->firstWhere('titulo', 'DAS de setembro');
        $this->assertSame('2026-10-20', $das['data']);
        $this->assertEqualsWithDelta(-22.5, $das['valor'], 0.001, '4,5% de 500');
    }

    public function test_vencimento_do_das_antecipa_no_fim_de_semana(): void
    {
        // 20/12/2026 é domingo: vence sexta 18/12.
        $this->assertSame('2026-12-18', PrevisaoDeCaixa::vencimentoDas(Carbon::parse('2026-11-01'))->toDateString());
        $this->assertSame('2026-10-20', PrevisaoDeCaixa::vencimentoDas(Carbon::parse('2026-09-01'))->toDateString());
    }

    public function test_admin_vai_para_o_fluxo_de_caixa_e_quem_nao_ve_lucro_nao(): void
    {
        $this->actingAs($this->admin())->get('/admin')->assertRedirect('/admin/fluxo-de-caixa');
        $this->actingAs($this->admin())->get('/admin?de=2026-10-01&ate=2026-10-05')->assertRedirect('/admin/fluxo-de-caixa?de=2026-10-01&ate=2026-10-05');
        $this->actingAs($this->admin())->get('/admin?mes=2026-09')->assertRedirect('/admin/indicadores?aba=acessos&mes=2026-09');

        $assinante = User::factory()->create(['role' => User::ROLE_SUBSCRIBER]);
        DB::table('role_permissions')->where('role', User::ROLE_SUBSCRIBER)->where('permission', 'financeiro.view')->update(['granted' => false]);
        $this->actingAs($assinante)->get('/admin')->assertRedirect('/admin/indicadores');
        $this->actingAs($assinante)->get('/admin/fluxo-de-caixa')->assertForbidden();
        // Indicadores sem financeiro.view: o cartão de lucro não sai do servidor.
        $this->actingAs($assinante)->get('/admin/indicadores?aba=hoje-e-mes')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('hojeMes.stats.revenue')
                ->missing('hojeMes.stats.netProfitMonth')
                ->where('hojeMes.analytics.kpis.margemPercentual', null));

        DB::table('role_permissions')->where('role', User::ROLE_SUBSCRIBER)->where('permission', 'relatorios.view')->update(['granted' => false]);
        $this->actingAs($assinante)->get('/admin')->assertRedirect('/admin/pedidos');
        $this->actingAs($assinante)->get('/admin/pedidos')->assertOk();

        // Rotas antigas continuam resolvendo.
        $this->assertSame(url('/admin'), route('admin.painel'));
        $this->assertSame(url('/admin/fluxo-de-caixa'), route('admin.fluxo-de-caixa.listar'));
    }

    public function test_indicadores_tem_as_cinco_abas_e_cada_uma_traz_so_o_seu(): void
    {
        $this->cenario();
        $admin = $this->admin();
        $esperado = [
            'visao-geral' => 'painel',
            'hoje-e-mes' => 'hojeMes',
            'caue-diego' => 'diagnosticos',
            'produtos' => 'produtos',
            'acessos' => 'visitantes',
        ];

        foreach ($esperado as $aba => $prop) {
            $this->actingAs($admin)->get("/admin/indicadores?aba={$aba}")
                ->assertOk()
                ->assertInertia(function ($page) use ($aba, $prop, $esperado) {
                    $page->where('aba', $aba)->has($prop);
                    foreach ($esperado as $outra => $outraProp) {
                        if ($outraProp !== $prop) {
                            $page->missing($outraProp);
                        }
                    }

                    return $page;
                });
        }

        // Sem ?aba= abre a Visão geral; aba desconhecida também.
        $this->actingAs($admin)->get('/admin/indicadores')->assertInertia(fn ($page) => $page->where('aba', 'visao-geral')->has('painel'));
        $this->actingAs($admin)->get('/admin/indicadores?aba=xyz')->assertInertia(fn ($page) => $page->where('aba', 'visao-geral'));

        // Lucro do mês na aba "Hoje e mês" = conta oficial.
        $conta = app(ContributionMargin::class)->periodo(Carbon::today()->startOfMonth(), Carbon::tomorrow());
        $this->actingAs($admin)->get('/admin/indicadores?aba=hoje-e-mes')
            ->assertInertia(fn ($page) => $page->where('hojeMes.stats.netProfitMonth', $conta['lucro']));
    }
}
