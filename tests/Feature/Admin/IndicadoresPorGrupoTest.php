<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Financeiro\Support\GruposDeCanais;
use App\Modules\Marketplace\Support\ContributionMargin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Indicadores por grupo (pedido do Lira, 17/11/2026): "métricas somadas só
 * dos marketplaces de um lado; do outro, em outra tela, meus e-commerces.
 * Nunca somar os dois grupos em nenhum lugar." A tela de Marketplaces não
 * pode ter receita de pedido do kazakora.com, e a de E-commerces não pode
 * ter pedido da Shopee.
 */
class IndicadoresPorGrupoTest extends TestCase
{
    use RefreshDatabase;

    private const MARKETPLACES = '/admin/painel/marketplaces/indicadores';

    private const ECOMMERCES = '/admin/painel/ecommerces/indicadores';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-17 15:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function pedido(string $origem, float $valor, string $quando, ?Product $produto = null): Order
    {
        $order = Order::create([
            'status' => Order::STATUS_PAID, 'origin' => $origem, 'external_order_id' => uniqid(),
            'shipping_name' => 'Cliente', 'shipping_phone' => '11999999999', 'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP', 'subtotal' => $valor, 'total' => $valor,
        ]);
        $order->forceFill(['created_at' => Carbon::parse($quando)])->save();
        if ($produto) {
            $order->items()->create(['product_id' => $produto->id, 'product_name' => $produto->name, 'product_price' => $valor, 'quantity' => 1, 'subtotal' => $valor]);
        }

        return $order;
    }

    public function test_visao_geral_de_cada_grupo_so_tem_os_canais_dele(): void
    {
        // Ontem (a série e os painéis são de dias completos).
        $this->pedido(Order::ORIGIN_SHOPEE, 100, '2026-11-16 10:00');
        $this->pedido(Order::ORIGIN_STORE, 40, '2026-11-16 11:00');

        $this->actingAs($this->admin())->get(self::MARKETPLACES)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Indicadores/Index', false)
                ->where('grupo', 'marketplaces')
                ->where('aba', 'visao-geral')
                ->where('canais', GruposDeCanais::canais('marketplaces'))
                ->where('serie.canais.shopee.receita.59', 100)
                ->missing('serie.canais.loja')
                ->has('composicao.7.shopee')
                ->missing('composicao.7.loja')
                ->where('painel.canais', fn ($c) => collect($c)->pluck('canal')->all() === [Order::ORIGIN_SHOPEE])
                ->where('kpis.ordersMonth', 1)
                ->where('kpis.averageTicket', 100)
                ->where('kpis.conversionRate', null));

        $this->actingAs($this->admin())->get(self::ECOMMERCES)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('grupo', 'ecommerces')
                ->where('serie.canais.loja.receita.59', 40)
                ->missing('serie.canais.shopee')
                ->has('composicao.7.loja')
                ->missing('composicao.7.shopee')
                ->where('painel.canais', fn ($c) => ! in_array(Order::ORIGIN_SHOPEE, collect($c)->pluck('canal')->all(), true)
                    && in_array(Order::ORIGIN_STORE, collect($c)->pluck('canal')->all(), true))
                ->where('kpis.ordersMonth', 1)
                ->where('kpis.averageTicket', 40));
    }

    public function test_hoje_e_mes_de_cada_grupo_so_soma_os_canais_dele(): void
    {
        $this->pedido(Order::ORIGIN_SHOPEE, 100, '2026-11-17 10:00');
        $this->pedido(Order::ORIGIN_STORE, 40, '2026-11-17 11:00');
        $this->pedido(Order::ORIGIN_MERCADO_LIVRE, 60, '2026-11-05 11:00');
        $inicio = Carbon::parse('2026-11-01');
        $fim = Carbon::parse('2026-11-18');
        $margem = app(ContributionMargin::class);
        $contaMarketplaces = $margem->conta($inicio, $fim, GruposDeCanais::canais('marketplaces'));
        $contaEcommerces = $margem->conta($inicio, $fim, GruposDeCanais::canais('ecommerces'));

        $this->actingAs($this->admin())->get(self::MARKETPLACES.'?aba=hoje-e-mes')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('aba', 'hoje-e-mes')
                ->where('hojeMes.stats.revenueToday', 100)
                ->where('hojeMes.stats.revenue', 160)
                ->where('hojeMes.stats.revenueTotal', 160)
                ->where('hojeMes.stats.ordersMonth', 2)
                ->where('hojeMes.stats.netProfitMonth', fn ($v) => abs((float) $v - $contaMarketplaces['lucro']) < 0.001)
                ->where('hojeMes.revenueByChannel', fn ($linhas) => collect($linhas)->pluck('origin')->sort()->values()->all() === [Order::ORIGIN_MERCADO_LIVRE, Order::ORIGIN_SHOPEE])
                ->where('hojeMes.analytics.mensal', fn ($meses) => ! array_key_exists(Order::ORIGIN_STORE, collect($meses)->last()['canais'])));

        $this->actingAs($this->admin())->get(self::ECOMMERCES.'?aba=hoje-e-mes')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('hojeMes.stats.revenueToday', 40)
                ->where('hojeMes.stats.revenue', 40)
                ->where('hojeMes.stats.ordersMonth', 1)
                ->where('hojeMes.stats.netProfitMonth', fn ($v) => abs((float) $v - $contaEcommerces['lucro']) < 0.001)
                ->where('hojeMes.revenueByChannel', [['origin' => Order::ORIGIN_STORE, 'total' => 40]]));

        // A conta de cada grupo é só dele: a soma dos dois não aparece em lugar nenhum.
        $this->assertEqualsWithDelta(160.0, $contaMarketplaces['receita'], 0.001);
        $this->assertEqualsWithDelta(40.0, $contaEcommerces['receita'], 0.001);
    }

    public function test_produtos_de_cada_grupo(): void
    {
        $doMarketplace = Product::factory()->create(['name' => 'Do marketplace']);
        $doSite = Product::factory()->create(['name' => 'Do site']);
        $this->pedido(Order::ORIGIN_SHOPEE, 100, '2026-11-16 10:00', $doMarketplace);
        $this->pedido(Order::ORIGIN_STORE, 40, '2026-11-16 11:00', $doSite);

        $this->actingAs($this->admin())->get(self::MARKETPLACES.'?aba=produtos')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('produtos.topProducts', fn ($p) => collect($p)->pluck('name')->all() === ['Do marketplace']));

        $this->actingAs($this->admin())->get(self::ECOMMERCES.'?aba=produtos')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('produtos.topProducts', fn ($p) => collect($p)->pluck('name')->all() === ['Do site']));
    }

    public function test_aba_fora_do_grupo_cai_na_visao_geral(): void
    {
        $this->actingAs($this->admin())->get(self::ECOMMERCES.'?aba=caue-diego')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('aba', 'visao-geral')->missing('diagnosticos'));

        $this->actingAs($this->admin())->get(self::MARKETPLACES.'?aba=acessos')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('aba', 'visao-geral')->missing('visitantes'));
    }

    public function test_endereco_antigo_redireciona_para_o_grupo(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/indicadores?aba=acessos')
            ->assertRedirect(self::ECOMMERCES.'?aba=acessos');
        $this->actingAs($admin)->get('/admin/indicadores?aba=visitas-ferrakora&mes=2026-10')
            ->assertRedirect(self::ECOMMERCES.'?aba=visitas-ferrakora&mes=2026-10');
        $this->actingAs($admin)->get('/admin/indicadores?canal=loja&dias=14')
            ->assertRedirect(self::ECOMMERCES.'?canal=loja&dias=14');
        $this->actingAs($admin)->get('/admin/indicadores?aba=hoje-e-mes')
            ->assertRedirect(self::MARKETPLACES.'?aba=hoje-e-mes');
        $this->actingAs($admin)->get('/admin/indicadores')
            ->assertRedirect(self::MARKETPLACES);
    }
}
