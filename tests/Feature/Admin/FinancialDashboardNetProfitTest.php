<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelAdSpend;
use App\Modules\Marketplace\Models\OrderChannelFee;
use App\Modules\Marketplace\Support\ContributionMargin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Resultado e margem (/admin/dashboard-financeiro), reestruturado em
 * 2026-10-19. Todo número de lucro é o da conta oficial
 * (ContributionMargin::periodo e porCanal), pelo período escolhido.
 *
 * Regras de antes que continuam valendo e estão aqui: taxa do canal entra
 * no lucro (2026-08-14), taxa vai para o mês do PEDIDO e não do backfill,
 * frete pago pelo comprador não é receita (2026-08-15), Amazon com taxa do
 * Bling e frete dos Correios (2026-09-25/29), custo manual da linha não
 * multiplica pela quantidade.
 *
 * Valores com centavos fracionários de propósito: o JSON do Inertia vira
 * inteiro quando o float é redondo e o ->where() compara estrito.
 */
class FinancialDashboardNetProfitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Cada teste olha uma parte da conta; o imposto tem teste próprio.
        config(['loja.simples.aliquota' => 0]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function makeOrder(array $attributes = []): Order
    {
        return Order::create(array_merge([
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_STORE,
            'shipping_name' => 'Cliente',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X',
            'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 100,
            'total' => 100,
        ], $attributes));
    }

    public function test_sem_vendas_a_tela_abre_zerada_e_lista_os_canais_sem_venda(): void
    {
        $this->actingAs($this->admin())->get('/admin/dashboard-financeiro')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filtro.periodo', 'mes')
                ->where('filtro.de', Carbon::today()->startOfMonth()->toDateString())
                ->where('filtro.ate', Carbon::today()->toDateString())
                ->where('conta.lucro', 0)
                ->where('conta.pedidos', 0)
                ->where('canais.linhas', [])
                ->where('canais.semVendas', ['Shopee', 'Mercado Livre', 'TikTok Shop', 'Amazon', 'KazaKora (site)'])
                // Removidos na reestruturação.
                ->missing('summary')
                ->missing('netProfit')
                ->missing('cashFlowSeries')
                ->missing('marketplaceMetrics'));
    }

    public function test_extrato_do_periodo_e_a_conta_oficial_com_imposto(): void
    {
        config(['loja.simples.aliquota' => 4.5, 'loja.simples.desde' => '2026-01-01']);
        $product = Product::factory()->create(['price' => 60.375, 'cost_price' => 40.25]);

        $order = $this->makeOrder(['subtotal' => 120.75, 'total' => 120.75]);
        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_price' => 60.375,
            'quantity' => 2, // custo total = 2 * 40.25 = 80.50
            'subtotal' => 120.75,
        ]);

        OrderChannelFee::create([
            'order_id' => $order->id,
            'channel' => 'mercado_livre',
            'gross_amount' => 120.75,
            'fee_amount' => 15.30,
            'computed_at' => now(),
        ]);

        ChannelAdSpend::create(['date' => now()->toDateString(), 'channel' => 'shopee', 'spend' => 10.20]);
        ChannelAdSpend::create(['date' => now()->toDateString(), 'channel' => 'mercado_livre', 'spend' => 5.10]);

        $oficial = app(ContributionMargin::class)->periodo(Carbon::today()->startOfMonth(), Carbon::tomorrow());

        $this->actingAs($this->admin())->get('/admin/dashboard-financeiro')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('conta.receita', 120.75)
                ->where('conta.custo_produtos', 80.5)
                ->where('conta.taxas', 15.3)
                ->where('conta.ads', 15.3)
                // 120.75 - 80.50 - 15.30 - 15.30 = 9.65; - 5.43 de Simples = 4.22.
                ->where('conta.margem', 9.65)
                ->where('conta.imposto', 5.43)
                ->where('conta.lucro', 4.22)
                ->where('conta.lucro', $oficial['lucro'])
                ->where('conta.pedidos', 1)
                ->where('conta.aliquota', 4.5)
                // O site vende; Shopee e ML só gastaram com anúncio (prejuízo).
                ->where('canais.linhas.0.canal', 'loja')
                ->where('canais.linhas.0.margemPct', round(($oficial['lucro'] + 15.3) / 120.75 * 100, 2))
                ->where('canais.linhas', fn ($linhas) => collect($linhas)->firstWhere('canal', 'shopee')['lucro'] === -10.2
                    && collect($linhas)->firstWhere('canal', 'shopee')['margemPct'] === null));
    }

    /**
     * BUG REAL 2026-08-14: taxa gravada hoje (backfill) para pedido do mês
     * passado fica no mês do pedido. O período "Mês passado" e "Desde o
     * início" mostram esse pedido; "Este mês" não.
     */
    public function test_taxa_fica_no_mes_do_pedido_e_os_periodos_mudam_a_conta(): void
    {
        $lastMonthOrder = $this->makeOrder(['subtotal' => 100.75, 'total' => 100.75]);
        $lastMonthOrder->forceFill(['created_at' => now()->startOfMonth()->subDays(3)])->save();
        OrderChannelFee::create([
            'order_id' => $lastMonthOrder->id, 'channel' => 'shopee', 'gross_amount' => 100.75,
            'fee_amount' => 18.30, 'computed_at' => now(),
        ]);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/dashboard-financeiro')
            ->assertInertia(fn ($page) => $page->where('conta.taxas', 0)->where('conta.lucro', 0));

        $this->actingAs($admin)->get('/admin/dashboard-financeiro?periodo=mes_passado')
            ->assertInertia(fn ($page) => $page
                ->where('filtro.periodo', 'mes_passado')
                ->where('filtro.de', Carbon::today()->startOfMonth()->subMonthNoOverflow()->toDateString())
                ->where('filtro.ate', Carbon::today()->startOfMonth()->subDay()->toDateString())
                ->where('conta.lucro', 82.45));

        $this->actingAs($admin)->get('/admin/dashboard-financeiro?periodo=inicio')
            ->assertInertia(fn ($page) => $page
                ->where('filtro.periodo', 'inicio')
                ->where('filtro.de', $lastMonthOrder->created_at->toDateString())
                ->where('conta.lucro', 82.45));

        // Datas escolhidas (invertidas viram na ordem certa).
        $dia = $lastMonthOrder->created_at->toDateString();
        $this->actingAs($admin)->get("/admin/dashboard-financeiro?de={$dia}&ate=".Carbon::parse($dia)->subDays(2)->toDateString())
            ->assertInertia(fn ($page) => $page
                ->where('filtro.periodo', 'datas')
                ->where('filtro.ate', $dia)
                ->where('conta.receita', 100.75));
    }

    public function test_frete_pago_pelo_comprador_nao_entra_nas_vendas_nem_no_lucro(): void
    {
        $this->makeOrder(['subtotal' => 44.99, 'shipping_cost' => 13.25, 'total' => 58.24]);

        $this->actingAs($this->admin())->get('/admin/dashboard-financeiro')
            ->assertInertia(fn ($page) => $page
                ->where('conta.receita', 44.99)
                ->where('conta.lucro', 44.99)
                ->where('conta.frete', 0));
    }

    public function test_aviso_de_itens_vendidos_sem_custo(): void
    {
        $sem = Product::factory()->create(['is_active' => true, 'cost_price' => null]);
        Product::factory()->create(['is_active' => true, 'cost_price' => null]);
        Product::factory()->create(['is_active' => true, 'cost_price' => 5.25]);
        $order = $this->makeOrder(['subtotal' => 50, 'total' => 50]);
        $order->items()->create(['product_id' => $sem->id, 'product_name' => $sem->name, 'product_price' => 50, 'quantity' => 1, 'subtotal' => 50]);
        // Item avulso (sem produto e sem custo manual) também conta, e não quebra a conta.
        $order->items()->create(['product_id' => null, 'product_name' => 'Serviço avulso', 'product_price' => 0, 'quantity' => 1, 'subtotal' => 0]);

        $this->actingAs($this->admin())->get('/admin/dashboard-financeiro')
            ->assertInertia(fn ($page) => $page
                ->where('confianca.itensSemCusto', 2)
                ->where('confianca.produtosAtivos', 3)
                ->where('confianca.produtosAtivosSemCusto', 2)
                ->where('conta.custo_produtos', 0)
                ->where('confianca.linkSemCusto', fn ($link) => str_starts_with($link, '/admin/fluxo-de-caixa?aba=vendas&sem_custo=1')));
    }

    /**
     * Amazon com dado real: taxa do Bling, frete dos Correios da nossa
     * pré-postagem e o frete do comprador nas vendas (o envio é da loja).
     */
    public function test_amazon_usa_taxa_real_e_frete_dos_correios(): void
    {
        $product = Product::factory()->create(['cost_price' => 30.25]);
        $order = $this->makeOrder(['origin' => Order::ORIGIN_AMAZON, 'subtotal' => 100.5, 'shipping_cost' => 20.25, 'total' => 120.75]);
        $order->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'product_price' => 50.25, 'quantity' => 2, 'subtotal' => 100.5]);
        OrderChannelFee::create(['order_id' => $order->id, 'channel' => 'amazon', 'gross_amount' => 100.5, 'fee_amount' => 15.25, 'source' => OrderChannelFee::SOURCE_API, 'computed_at' => now()]);
        \App\Modules\Marketplace\Models\CorreiosPrePostagem::create([
            'order_id' => $order->id, 'origin' => 'amazon', 'customer_name' => 'Maria', 'zip' => '13010000', 'street' => 'Rua',
            'number' => '1', 'neighborhood' => 'Centro', 'city' => 'Campinas', 'state' => 'SP', 'service_code' => '03298',
            'service_label' => 'PAC (contrato)', 'postage_price' => 22.75, 'weight_grams' => 400, 'dimension_format' => '2',
            'content_items' => [], 'status' => \App\Modules\Marketplace\Models\CorreiosPrePostagem::STATUS_GERADA,
        ]);

        // 120,75 − 60,50 − 15,25 − 22,75 = 22,25
        $this->actingAs($this->admin())->get('/admin/dashboard-financeiro')
            ->assertInertia(fn ($page) => $page
                ->where('conta.frete', 22.75)
                ->where('conta.lucro', 22.25)
                ->where('canais.linhas.0.canal', 'amazon')
                ->where('canais.linhas.0.lucro', 22.25)
                ->where('canais.linhas.0.pedidosTaxaEstimada', 0));
    }

    public function test_custo_manual_da_linha_nao_multiplica_pela_quantidade(): void
    {
        $order = $this->makeOrder(['subtotal' => 100.5, 'total' => 100.5]);
        $order->items()->create(['product_id' => null, 'product_name' => 'Avulso', 'product_price' => 33.5, 'quantity' => 3, 'subtotal' => 100.5, 'manual_cost_price' => 45.25]);

        $this->actingAs($this->admin())->get('/admin/dashboard-financeiro')
            ->assertInertia(fn ($page) => $page->where('conta.custo_produtos', 45.25));
    }

    public function test_pedido_sem_taxa_real_marca_o_canal_como_taxa_estimada(): void
    {
        $comTaxa = $this->makeOrder(['origin' => Order::ORIGIN_SHOPEE, 'external_order_id' => 'SN1']);
        OrderChannelFee::create([
            'order_id' => $comTaxa->id, 'channel' => 'shopee', 'gross_amount' => 100, 'fee_amount' => 30,
            'source' => OrderChannelFee::SOURCE_API, 'computed_at' => now(),
        ]);
        $estimada = $this->makeOrder(['origin' => Order::ORIGIN_SHOPEE, 'external_order_id' => 'SN2']);
        OrderChannelFee::create([
            'order_id' => $estimada->id, 'channel' => 'shopee', 'gross_amount' => 100, 'fee_amount' => 20.5,
            'source' => OrderChannelFee::SOURCE_ESTIMATE, 'computed_at' => now(),
        ]);
        $this->makeOrder(['origin' => Order::ORIGIN_SHOPEE, 'external_order_id' => 'SN3']);
        // Site não tem "taxa do canal" a esperar: não marca.
        $this->makeOrder();

        $this->actingAs($this->admin())->get('/admin/dashboard-financeiro')
            ->assertInertia(fn ($page) => $page
                ->where('confianca.taxaEstimada.pedidos', 2)
                ->where('confianca.taxaEstimada.valor', 20.5)
                ->where('confianca.taxaEstimada.canais', ['Shopee'])
                ->where('canais.linhas', fn ($linhas) => collect($linhas)->firstWhere('canal', 'shopee')['pedidosTaxaEstimada'] === 2
                    && collect($linhas)->firstWhere('canal', 'loja')['pedidosTaxaEstimada'] === 0));
    }

    /**
     * "A liberar": só o que não foi pago e tem data de hoje em diante. Na
     * Amazon, RELEASED já foi pago; DEFERRED é retido sem data.
     */
    public function test_a_liberar_ignora_pago_e_data_passada_e_separa_amazon_retida(): void
    {
        $linha = fn (array $a) => DB::table('marketplace_settlement_details')->insert($a + [
            'statement_id' => '', 'external_sku_id' => '', 'transaction_type' => 'Pedido', 'source_file' => 't.xlsx',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $hoje = Carbon::today();
        $linha(['channel' => 'tiktok_shop', 'external_order_id' => 'T1', 'status' => 'Pagos', 'settlement_date' => $hoje->copy()->addDays(2)->toDateString(), 'payout_amount' => 50.25]);
        $linha(['channel' => 'tiktok_shop', 'external_order_id' => 'T2', 'status' => 'A pagar', 'settlement_date' => $hoje->copy()->addDays(3)->toDateString(), 'payout_amount' => 40.5]);
        $linha(['channel' => 'tiktok_shop', 'external_order_id' => 'T3', 'status' => 'A pagar', 'settlement_date' => $hoje->copy()->subDays(3)->toDateString(), 'payout_amount' => 99.75]);
        $linha(['channel' => 'amazon', 'external_order_id' => 'A1', 'transaction_type' => 'Order', 'status' => 'RELEASED', 'settlement_date' => $hoje->toDateString(), 'payout_amount' => 70.25]);
        $linha(['channel' => 'amazon', 'external_order_id' => 'A2', 'transaction_type' => 'Order', 'status' => 'DEFERRED', 'settlement_date' => $hoje->copy()->subDays(1)->toDateString(), 'payout_amount' => 30.5]);

        $this->actingAs($this->admin())->get('/admin/dashboard-financeiro')
            ->assertInertia(fn ($page) => $page->where('receber.aLiberar', function ($canais) use ($hoje) {
                $c = collect($canais)->keyBy('canal');

                return $c['tiktok_shop']['total'] === 40.5
                    && $c['tiktok_shop']['datas'] === [['data' => $hoje->copy()->addDays(3)->toDateString(), 'valor' => 40.5]]
                    && $c['amazon']['total'] == 0 && $c['amazon']['datas'] === []
                    && $c['amazon']['retido'] === 30.5 && $c['amazon']['retidoPedidos'] === 1
                    && $c['shopee']['temExtrato'] === false && $c['mercado_livre']['temExtrato'] === false;
            })
                ->where('receber.disponivel.1.nome', 'Mercado Pago (ML + site)')
                ->where('receber.disponivel.2.valor', null)
                ->where('receber.disponivel.2.motivo', 'Saldo não disponível pela integração.'));

        // A projeção do Fluxo de Caixa usa a mesma regra.
        $projecao = app(\App\Modules\Financeiro\Support\PrevisaoDeCaixa::class)->receivableProjection(['shopee' => null, 'mercado_livre' => null]);
        $this->assertSame(40.5, $projecao['periods'][0]['total']);
    }

    public function test_anuncios_mostram_sem_dado_em_vez_de_zero(): void
    {
        $this->makeOrder(['origin' => Order::ORIGIN_SHOPEE, 'subtotal' => 200.5, 'total' => 200.5]);
        ChannelAdSpend::create(['date' => now()->toDateString(), 'channel' => 'shopee', 'spend' => 10.25, 'attributed_orders' => 2, 'attributed_gmv' => 80.5]);
        ChannelAdSpend::create(['date' => now()->toDateString(), 'channel' => 'mercado_livre', 'spend' => 5.5, 'attributed_orders' => 0, 'attributed_gmv' => 0]);

        $this->actingAs($this->admin())->get('/admin/dashboard-financeiro')
            ->assertInertia(fn ($page) => $page->where('anuncios.linhas', function ($linhas) {
                $l = collect($linhas)->keyBy('canal');

                return $l['shopee']['gasto'] === 10.25 && $l['shopee']['vendas'] === 80.5 && $l['shopee']['roas'] === 7.85
                    && $l['mercado_livre']['vendas'] === null && $l['mercado_livre']['roas'] === null
                    && $l['tiktok_shop']['gasto'] == 0 && $l['tiktok_shop']['vendas'] === null
                    && ! $l->has('amazon')
                    // Meta sem configuração no teste: gasto não disponível.
                    && $l['meta_ads']['gasto'] === null && $l['meta_ads']['link']['href'] === '/admin/publicidade/meta-ads';
            })->has('anuncios.serie', 14));
    }
}
