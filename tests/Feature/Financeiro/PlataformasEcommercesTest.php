<?php

namespace Tests\Feature\Financeiro;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Financeiro\Mail\ResultadoDoDia;
use App\Modules\Financeiro\Support\GruposDeCanais;
use App\Modules\Financeiro\Support\ResultadoDiario;
use App\Modules\Financeiro\Support\ResultadoPorGrupo;
use App\Modules\Marketplace\Models\ChannelAdSpend;
use App\Modules\Marketplace\Support\ContributionMargin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Plataformas x E-commerces (pedido do Lira em 16/11/2026): tela nova no
 * Financeiro e seção no e-mail diário, com a conta de porCanal() somada
 * pelos grupos de GruposDeCanais. A soma dos grupos tem que bater com
 * periodo().
 */
class PlataformasEcommercesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['loja.email_relatorios' => 'dono@exemplo.com', 'loja.simples.aliquota' => 4.5, 'loja.simples.desde' => '2026-09-01']);
        Carbon::setTestNow(Carbon::parse('2026-11-16 15:00:00', 'America/Sao_Paulo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pedido(array $pedido, float $subtotal, string $quando = '2026-11-16 10:00:00'): Order
    {
        $order = Order::create(array_merge([
            'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_STORE,
            'shipping_name' => 'Cliente', 'shipping_phone' => '11999999999', 'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => $subtotal, 'discount_amount' => 0, 'shipping_cost' => 0, 'total' => $subtotal,
        ], $pedido));
        $order->forceFill(['created_at' => Carbon::parse($quando, 'America/Sao_Paulo')])->save();
        $order->items()->create([
            'product_id' => null, 'product_name' => 'Avulso', 'product_price' => $subtotal, 'quantity' => 1,
            'subtotal' => $subtotal, 'manual_cost_price' => round($subtotal / 2, 2),
        ]);

        return $order;
    }

    private function ads(string $canal, float $valor, string $dia = '2026-11-16'): void
    {
        ChannelAdSpend::create(['date' => $dia, 'channel' => $canal, 'spend' => $valor, 'impressions' => 0, 'clicks' => 0, 'attributed_orders' => 0, 'attributed_gmv' => 0]);
    }

    /** Um pouco de tudo: marketplaces, os dois sites, Amazon sem ADS, origem antiga e um mês anterior. */
    private function cenario(): void
    {
        $this->pedido(['origin' => Order::ORIGIN_MERCADO_LIVRE, 'external_order_id' => 'ML1'], 200.10);
        $this->pedido(['origin' => Order::ORIGIN_SHOPEE, 'external_order_id' => 'SHP1'], 80.30);
        $this->pedido(['origin' => Order::ORIGIN_AMAZON, 'external_order_id' => 'AMZ1'], 150.20);
        $this->pedido([], 120.40); // kazakora.com
        $this->pedido(['site_origem' => Order::SITE_FERRAKORA], 300.50);
        $this->pedido(['origin' => 'site'], 40.60); // origem antiga: cai em Outros
        $this->pedido(['origin' => Order::ORIGIN_MERCADO_LIVRE, 'external_order_id' => 'ML0'], 90.70, '2026-10-20 12:00:00');

        $this->ads(Order::ORIGIN_SHOPEE, 10.25);
        $this->ads(Order::ORIGIN_MERCADO_LIVRE, 7.15, '2026-10-20');
        $this->ads('meta_ads', 15.35); // Meta da KazaKora entra no canal loja.
        $this->ads(Order::SITE_FERRAKORA, 20.45);
    }

    public function test_grupos_somam_o_total_do_periodo(): void
    {
        $this->cenario();
        $de = Carbon::parse('2026-10-01');
        $ate = Carbon::parse('2026-11-16');

        $r = app(ResultadoPorGrupo::class)->porMes($de, $ate);
        $periodo = app(ContributionMargin::class)->periodo($de->copy(), $ate->copy()->addDay());

        $this->assertSame(['plataformas', 'ecommerces', 'outros'], array_keys($r['grupos']));
        foreach (['receita', 'ads', 'lucro', 'imposto', 'custo_produtos'] as $campo) {
            $soma = array_sum(array_map(fn ($g) => $g['total'][$campo], $r['grupos']));
            $this->assertEqualsWithDelta($periodo[$campo], $soma, 0.02, $campo);
            $this->assertEqualsWithDelta($periodo[$campo], $r['total'][$campo], 0.02, $campo);
        }

        $plataformas = $r['grupos']['plataformas'];
        $ecommerces = $r['grupos']['ecommerces'];
        $this->assertSame(4, $plataformas['total']['pedidos']);
        $this->assertEqualsWithDelta(200.10 + 80.30 + 150.20 + 90.70, $plataformas['total']['receita'], 0.001);
        $this->assertEqualsWithDelta(10.25 + 7.15, $plataformas['total']['ads'], 0.001);
        $this->assertSame(2, $ecommerces['total']['pedidos']);
        $this->assertEqualsWithDelta(15.35 + 20.45, $ecommerces['total']['ads'], 0.001);
        $this->assertEqualsWithDelta(round((15.35 + 20.45) / (120.40 + 300.50) * 100, 1), $ecommerces['total']['ads_pct'], 0.001);
        $this->assertSame(1, $r['grupos']['outros']['total']['pedidos']);

        // Mês a mês: outubro só com o ML, novembro com o resto; o total é a soma dos meses.
        $this->assertSame(['2026-10', '2026-11'], array_column($plataformas['meses'], 'mes'));
        $this->assertSame(1, $plataformas['meses'][0]['total']['pedidos']);
        $this->assertSame(3, $plataformas['meses'][1]['total']['pedidos']);
        $this->assertEqualsWithDelta(
            $plataformas['meses'][0]['total']['lucro'] + $plataformas['meses'][1]['total']['lucro'],
            $plataformas['total']['lucro'], 0.02);

        // Lucro por canal é o do porCanal(), sem conta nova.
        $novembro = app(ContributionMargin::class)->porCanal(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-17'));
        $loja = collect($ecommerces['meses'][1]['canais'])->firstWhere('canal', 'loja');
        $this->assertEqualsWithDelta($novembro['loja']['lucro'], $loja['lucro'], 0.001);

        // Amazon marcada sem ADS integrado.
        $amazon = collect($plataformas['canais'])->firstWhere('canal', Order::ORIGIN_AMAZON);
        $this->assertTrue($amazon['sem_ads_integrado']);
        $this->assertSame(GruposDeCanais::PLATAFORMAS, GruposDeCanais::grupoDe('tiktok_shop'));
        $this->assertSame(GruposDeCanais::ECOMMERCES, GruposDeCanais::grupoDe('ferrakora'));
        $this->assertSame(GruposDeCanais::OUTROS, GruposDeCanais::grupoDe(Order::ORIGIN_MANUAL_INVOICE));
    }

    public function test_tela_responde_com_os_dois_grupos_e_filtro_de_periodo(): void
    {
        $this->cenario();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/admin/financeiro/plataformas-e-ecommerces?de=2026-10-01&ate=2026-11-16&aba=ecommerces')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Financeiro/PlataformasEcommerces', false)
                ->where('filtros.de', '2026-10-01')
                ->where('filtros.ate', '2026-11-16')
                ->where('filtros.aba', 'ecommerces')
                ->has('resultado.grupos.plataformas.meses', 2)
                ->has('resultado.grupos.ecommerces.canais', count(GruposDeCanais::GRUPOS['ecommerces']))
                ->where('resultado.grupos.plataformas.total.pedidos', 4)
                ->where('resultado.grupos.ecommerces.total.pedidos', 2)
                ->has('resultado.grupos.outros')
                ->has('semAdsIntegrado'));

        // Padrão: este mês até hoje, aba Plataformas.
        $this->actingAs($admin)->get('/admin/financeiro/plataformas-e-ecommerces?de=lixo')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filtros.de', '2026-11-01')
                ->where('filtros.ate', '2026-11-16')
                ->where('filtros.aba', 'plataformas')
                ->has('resultado.grupos.plataformas.meses', 1)
                ->where('resultado.grupos.plataformas.total.pedidos', 3));
    }

    public function test_quem_nao_ve_financeiro_nao_abre_a_tela(): void
    {
        $assinante = User::factory()->create(['role' => User::ROLE_SUBSCRIBER]);
        DB::table('role_permissions')->where('role', User::ROLE_SUBSCRIBER)->where('permission', 'financeiro.view')->update(['granted' => false]);

        $this->actingAs($assinante)->get('/admin/financeiro/plataformas-e-ecommerces')->assertForbidden();
    }

    public function test_email_diario_mostra_plataformas_e_ecommerces_sem_perder_o_resto(): void
    {
        $this->cenario();

        $r = app(ResultadoDiario::class)->calcular(Carbon::parse('2026-11-16'));
        $html = (new ResultadoDoDia($r))->render();

        // O que já tinha continua.
        $this->assertStringContainsString('Lucro líquido do dia', $html);
        $this->assertStringContainsString('Como fechou o dia', $html);
        // A seção nova, com os dois grupos e os canais.
        $this->assertStringContainsString('Plataformas e E-commerces', $html);
        $this->assertStringContainsString('Plataformas', $html);
        $this->assertStringContainsString('E-commerces', $html);
        $this->assertStringContainsString('Mercado Livre', $html);
        $this->assertStringContainsString('FerraKora', $html);
        $this->assertStringContainsString('Amazon sem integração de ADS', $html);

        // Grupos do dia somam o lucro do dia (mesma conta).
        $somaDia = array_sum(array_map(fn ($g) => $g['total']['lucro'], $r['grupos']['dia']));
        $this->assertEqualsWithDelta($r['dia']['lucro'], $somaDia, 0.02);
        $somaMes = array_sum(array_map(fn ($g) => $g['total']['lucro'], $r['grupos']['mes']));
        $this->assertEqualsWithDelta($r['mes']['lucro'], $somaMes, 0.02);
    }

    public function test_relatorios_somam_a_mesma_venda_da_conta_oficial(): void
    {
        // Auditoria de 27/11/2026: a tela Relatórios somava pedido aguardando pagamento e nota técnica
        // de devolução e mostrava mais receita que as outras telas.
        $this->pedido(['origin' => Order::ORIGIN_SHOPEE], 100);
        $this->pedido(['origin' => Order::ORIGIN_SHOPEE, 'status' => Order::STATUS_PENDING], 900);
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $oficial = app(\App\Modules\Marketplace\Support\ContributionMargin::class)->porCanal(Carbon::parse('2026-11-16'), Carbon::parse('2026-11-17'));

        $this->actingAs($admin)->get('/admin/relatorios?from=2026-11-16&to=2026-11-16&grupo=marketplaces')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('summary.ordersCount', 1)
                ->where('summary.revenue', $oficial[Order::ORIGIN_SHOPEE]['receita'])
                ->where('summary.revenue', 100));
    }
}
