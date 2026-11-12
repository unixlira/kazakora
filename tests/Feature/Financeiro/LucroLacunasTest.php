<?php

namespace Tests\Feature\Financeiro;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Support\FreteEmbutido;
use App\Modules\Financeiro\Models\InsumoLancamento;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Models\ChannelAdSpend;
use App\Modules\Marketplace\Models\MelhorEnvioEnvio;
use App\Modules\Marketplace\Models\OrderChannelFee;
use App\Modules\Marketplace\Support\ContributionMargin;
use App\Services\MercadoLivre\MercadoLivreAdsService;
use App\Services\Shopee\ShopeeAdsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Auditoria do lucro de 12/11/2026 (pedido do Lira em 12/11/2026): insumos
 * por pedido e da tela Insumos, frete do Melhor Envio, devolução concluída
 * fora da venda e Meta da FerraKora no ADS.
 */
class LucroLacunasTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-12 15:00:00', 'America/Sao_Paulo'));
        config(['loja.insumos_por_pedido' => 2.0]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pedido(array $dados = [], float $custo = 0.0): Order
    {
        $this->seq++;
        $order = Order::create(array_merge([
            'status' => Order::STATUS_PAID, 'origin' => 'loja',
            'shipping_name' => 'Cliente', 'shipping_phone' => '85999999999', 'shipping_zip' => '60025-000',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'Fortaleza', 'shipping_state' => 'CE',
            'subtotal' => 100, 'shipping_cost' => 0, 'total' => 100,
        ], $dados));
        $order->items()->create([
            'product_id' => null, 'product_name' => 'Avulso '.$this->seq, 'product_price' => $order->subtotal,
            'quantity' => 1, 'subtotal' => $order->subtotal, 'manual_cost_price' => $custo ?: null,
        ]);

        return $order;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function hoje(): array
    {
        return [now()->startOfDay(), now()->startOfDay()->addDay()];
    }

    private function confereSomas(): void
    {
        [$de, $ate] = $this->hoje();
        $cm = app(ContributionMargin::class);
        $periodo = $cm->periodo($de, $ate);
        $porCanal = $cm->porCanal($de, $ate);

        foreach (['receita', 'custo_produtos', 'taxas', 'frete', 'insumos', 'ads', 'margem', 'lucro'] as $campo) {
            $this->assertEqualsWithDelta($periodo[$campo], array_sum(array_column($porCanal, $campo)), 0.011, "periodo x porCanal: {$campo}");
        }

        $item = $cm->porItem($de, $ate);
        $c = $item['canal'];
        $this->assertEqualsWithDelta(
            $periodo['margem'],
            array_sum(array_column($item['linhas'], 'margem')) - $c['ads'] - $c['flex'] + $c['bonificacoes'] - $c['cobrancas'] - $c['insumos_lancados'],
            0.011,
            'porItem x periodo: margem',
        );
    }

    // 1. Insumos ------------------------------------------------------------

    public function test_insumos_por_pedido_entram_na_margem_e_o_full_fica_de_fora(): void
    {
        $this->pedido(['origin' => 'loja', 'subtotal' => 100, 'total' => 100]);
        $this->pedido(['origin' => 'shopee', 'status' => Order::STATUS_SHIPPED, 'subtotal' => 50, 'total' => 50, 'external_order_id' => 'S1']);
        $full = $this->pedido(['origin' => 'mercado_livre', 'subtotal' => 80, 'total' => 80, 'external_order_id' => 'M1']);
        DB::table('channel_shipments')->insert(['order_id' => $full->id, 'channel' => 'mercado_livre', 'shipping_method' => 'fulfillment', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $this->pedido(['status' => Order::STATUS_CANCELLED]);

        [$de, $ate] = $this->hoje();
        $cm = app(ContributionMargin::class);
        $periodo = $cm->periodo($de, $ate);
        $porCanal = $cm->porCanal($de, $ate);

        $this->assertSame(4.0, $periodo['insumos']);
        $this->assertSame(4.0, $periodo['insumos_embalagem']);
        $this->assertSame(0.0, $periodo['insumos_lancados']);
        $this->assertSame(230.0 - 4.0, $periodo['margem']);
        $this->assertSame(2.0, $porCanal['loja']['insumos']);
        $this->assertSame(2.0, $porCanal['shopee']['insumos']);
        $this->assertSame(0.0, $porCanal['mercado_livre']['insumos']);
        $this->assertEqualsWithDelta(4.0, array_sum(array_column($cm->porItem($de, $ate)['linhas'], 'insumos')), 0.001);
        $this->confereSomas();

        config(['loja.insumos_por_pedido' => 0]);
        $this->assertSame(0.0, $cm->periodo($de, $ate)['insumos']);
    }

    public function test_lancamentos_da_tela_insumos_entram_pela_data_e_rateiam_entre_os_canais(): void
    {
        $this->pedido(['origin' => 'loja']);
        $this->pedido(['origin' => 'shopee', 'external_order_id' => 'S1']);
        $this->pedido(['origin' => 'shopee', 'external_order_id' => 'S2']);
        InsumoLancamento::create(['data' => '2026-11-12', 'descricao' => 'Fita e papel', 'categoria' => 'escritorio', 'valor' => 100]);
        InsumoLancamento::create(['data' => '2026-11-11', 'descricao' => 'Ontem', 'categoria' => 'galpao', 'valor' => 999]);

        [$de, $ate] = $this->hoje();
        $cm = app(ContributionMargin::class);
        $periodo = $cm->periodo($de, $ate);
        $porCanal = $cm->porCanal($de, $ate);

        $this->assertSame(100.0, $periodo['insumos_lancados']);
        $this->assertSame(6.0, $periodo['insumos_embalagem']);
        $this->assertSame(106.0, $periodo['insumos']);
        // Rateio pelos pedidos embalados: 1 do site, 2 da Shopee.
        $this->assertSame(33.33, $porCanal['loja']['insumos_lancados']);
        $this->assertSame(66.67, $porCanal['shopee']['insumos_lancados']);
        $this->assertSame(100.0, $cm->porItem($de, $ate)['canal']['insumos_lancados']);
        $this->assertSame(66.67, $cm->porItem($de, $ate, 'shopee')['canal']['insumos_lancados']);
        $this->confereSomas();

        // Dia sem pedido: o lançamento fica no site, sem sumir da soma.
        $ontem = [now()->subDay()->startOfDay(), now()->startOfDay()];
        $this->assertSame(999.0, $cm->periodo(...$ontem)['insumos']);
        $this->assertSame(999.0, $cm->porCanal(...$ontem)['loja']['insumos']);
    }

    public function test_embalagem_lancada_nao_conta_em_dobro_com_o_valor_por_pedido(): void
    {
        // Decisão do Lira em 12/11/2026: mantém os R$ 2 por pedido. A compra de embalagem
        // lançada na tela fica só como histórico; escritório e galpão continuam entrando.
        $this->pedido(['origin' => 'loja']);
        InsumoLancamento::create(['data' => '2026-11-12', 'descricao' => 'Etiquetas', 'categoria' => 'embalagem', 'valor' => 94.90]);
        InsumoLancamento::create(['data' => '2026-11-12', 'descricao' => 'Papel', 'categoria' => 'escritorio', 'valor' => 10]);

        [$de, $ate] = $this->hoje();
        $cm = app(ContributionMargin::class);
        $this->assertSame(10.0, $cm->periodo($de, $ate)['insumos_lancados']);
        $this->assertSame(12.0, $cm->periodo($de, $ate)['insumos']);
        $this->confereSomas();

        // Embalagem por pedido desligada: aí vale a compra real.
        config(['loja.insumos_por_pedido' => 0]);
        $this->assertSame(104.9, $cm->periodo($de, $ate)['insumos']);
    }

    // 2. Melhor Envio --------------------------------------------------------

    public function test_etiqueta_do_melhor_envio_entra_no_frete_e_abate_o_turbo(): void
    {
        $a = $this->pedido();
        MelhorEnvioEnvio::create(['order_id' => $a->id, 'status' => MelhorEnvioEnvio::STATUS_GERADA, 'preco' => 25.50]);
        $turbo = $this->pedido(['shipping_carrier_name' => FreteEmbutido::TURBO, 'shipping_cost' => 10, 'total' => 110]);
        MelhorEnvioEnvio::create(['order_id' => $turbo->id, 'status' => MelhorEnvioEnvio::STATUS_PAGA, 'preco' => 30]);
        $cancelada = $this->pedido();
        MelhorEnvioEnvio::create(['order_id' => $cancelada->id, 'status' => MelhorEnvioEnvio::STATUS_GERADA, 'preco' => 15, 'cancelada_em' => now()]);
        $cotada = $this->pedido();
        MelhorEnvioEnvio::create(['order_id' => $cotada->id, 'status' => MelhorEnvioEnvio::STATUS_COTADA, 'preco' => 40]);
        $foraDaVenda = $this->pedido(['status' => Order::STATUS_CANCELLED]);
        MelhorEnvioEnvio::create(['order_id' => $foraDaVenda->id, 'status' => MelhorEnvioEnvio::STATUS_GERADA, 'preco' => 12]);

        [$de, $ate] = $this->hoje();
        $cm = app(ContributionMargin::class);
        $periodo = $cm->periodo($de, $ate);

        // 25,50 + (30 − TURBO 10) ; a cancelada e a só cotada não contam.
        $this->assertSame(45.5, $periodo['frete']);
        $this->assertSame(45.5, $cm->porCanal($de, $ate)['loja']['frete']);
        $this->assertSame(['loja' => 12.0], ContributionMargin::cobrancasSemPedido($de, $ate));
        $this->assertSame(12.0, $periodo['taxas']);
        $this->assertEqualsWithDelta(45.5, array_sum(array_column($cm->porItem($de, $ate)['linhas'], 'frete')), 0.001);
        $this->confereSomas();
    }

    // 3. Devolução concluída ----------------------------------------------

    public function test_devolucao_conferida_da_shopee_sai_da_venda_como_o_pedido_3435(): void
    {
        $devolvido = $this->pedido(['origin' => 'shopee', 'status' => Order::STATUS_SHIPPED, 'subtotal' => 60.30, 'total' => 60.30, 'external_order_id' => '3435'], custo: 20);
        OrderChannelFee::create(['order_id' => $devolvido->id, 'channel' => 'shopee', 'gross_amount' => 60.30, 'fee_amount' => 12, 'source' => 'api', 'computed_at' => now()]);
        DB::table('marketplace_returns')->insert([
            'channel' => 'shopee', 'external_id' => 'r3435', 'order_id' => $devolvido->id, 'external_order_id' => '3435',
            'kind' => 'devolucao', 'situacao' => 'conferida', 'return_status' => 'delivered', 'money_status' => 'refunded',
            'refund_amount' => 60.30, 'verdict' => 'ok', 'devolucao_fiscal_status' => 'emitida', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Devolução ainda aberta (produto não voltou): continua venda.
        $aberto = $this->pedido(['origin' => 'shopee', 'subtotal' => 40, 'total' => 40, 'external_order_id' => '3436']);
        DB::table('marketplace_returns')->insert([
            'channel' => 'shopee', 'external_id' => 'r3436', 'order_id' => $aberto->id, 'kind' => 'devolucao',
            'situacao' => 'aguardando_envio', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Veredito "não recebemos": continua venda.
        $naoVoltou = $this->pedido(['origin' => 'shopee', 'subtotal' => 30, 'total' => 30, 'external_order_id' => '3437']);
        DB::table('marketplace_returns')->insert([
            'channel' => 'shopee', 'external_id' => 'r3437', 'order_id' => $naoVoltou->id, 'kind' => 'devolucao',
            'situacao' => 'conferida', 'verdict' => 'nao_recebido', 'created_at' => now(), 'updated_at' => now(),
        ]);

        [$de, $ate] = $this->hoje();
        $cm = app(ContributionMargin::class);
        $periodo = $cm->periodo($de, $ate);

        $this->assertSame(70.0, $periodo['receita']);
        $this->assertSame(0.0, $periodo['custo_produtos']);
        $this->assertSame(0.0, $periodo['taxas']);
        $this->assertSame(round(70 * ContributionMargin::aliquotaSimples() / 100, 2), $periodo['imposto']);
        $this->assertSame(2, $cm->porCanal($de, $ate)['shopee']['pedidos']);
        $this->assertFalse(Order::query()->vendaEfetiva()->whereKey($devolvido->id)->exists());
        $this->assertTrue(Order::query()->vendaEfetiva()->whereKey($aberto->id)->exists());
        $this->confereSomas();
    }

    public function test_nfe_de_devolucao_autorizada_tira_o_pedido_da_venda(): void
    {
        $venda = $this->pedido(['origin' => 'mercado_livre', 'subtotal' => 90, 'total' => 90, 'external_order_id' => 'M9']);
        $chave = '35261165604590000107550020000099991469013333';
        Invoice::create(['order_id' => $venda->id, 'serie' => 2, 'numero' => 9999, 'status' => Invoice::STATUS_AUTHORIZED, 'chave_acesso' => $chave,
            'origem' => Invoice::ORIGEM_PEDIDO, 'ambiente' => Invoice::AMBIENTE_PRODUCAO, 'valor_total' => 90]);
        $devolucao = $this->pedido(['origin' => Order::ORIGIN_SALES_RETURN_INVOICE, 'fiscal_operation_type' => 'sales_return', 'fiscal_referenced_nfe_key' => $chave]);
        $notaDevolucao = Invoice::create(['order_id' => $devolucao->id, 'serie' => 2, 'numero' => 10000, 'status' => 'pending',
            'origem' => Invoice::ORIGEM_PEDIDO, 'ambiente' => Invoice::AMBIENTE_PRODUCAO, 'valor_total' => 90]);

        [$de, $ate] = $this->hoje();
        $cm = app(ContributionMargin::class);

        // Nota de devolução ainda não autorizada: a venda continua.
        $this->assertSame(90.0, $cm->periodo($de, $ate)['receita']);

        $notaDevolucao->forceFill(['status' => Invoice::STATUS_AUTHORIZED])->save();
        $this->assertSame(0.0, $cm->periodo($de, $ate)['receita']);
        $this->assertFalse(Order::query()->vendaEfetiva()->whereKey($venda->id)->exists());
    }

    // 4. Meta da FerraKora --------------------------------------------------

    public function test_meta_da_ferrakora_grava_canal_proprio_e_entra_no_ads(): void
    {
        config([
            'services.meta_ads.access_token' => 'token-teste', 'services.meta_ads.page_id' => '456',
            'services.meta_ads.ad_account_id' => '111', 'services.meta_ads.ferrakora_ad_account_id' => 'act_222',
        ]);
        $this->mock(ShopeeAdsService::class);
        $this->mock(MercadoLivreAdsService::class);
        Http::fake([
            'graph.facebook.com/*act_111/insights*' => Http::response(['data' => [['date_start' => '2026-11-12', 'spend' => '50.00', 'impressions' => '10', 'clicks' => '1']]]),
            'graph.facebook.com/*act_222/insights*' => Http::sequence()
                ->push(['data' => [['date_start' => '2026-11-12', 'spend' => '30.00', 'impressions' => '9', 'clicks' => '2']]])
                ->push(['data' => [['date_start' => '2026-11-12', 'spend' => '35.00', 'impressions' => '9', 'clicks' => '2']]]),
        ]);

        $this->artisan('ads:sync-spend', ['--dias' => 1])->assertSuccessful();
        $this->artisan('ads:sync-spend', ['--dias' => 1])->assertSuccessful();

        // Idempotente por data/canal: a segunda rodada atualiza a mesma linha.
        $this->assertSame(1, ChannelAdSpend::query()->where('channel', 'ferrakora')->count());
        $this->assertDatabaseHas('channel_ad_spends', ['channel' => 'ferrakora', 'date' => '2026-11-12', 'spend' => 35.0]);
        $this->assertDatabaseHas('channel_ad_spends', ['channel' => 'meta_ads', 'date' => '2026-11-12', 'spend' => 50.0]);

        [$de, $ate] = $this->hoje();
        $cm = app(ContributionMargin::class);
        $this->assertSame(85.0, $cm->periodo($de, $ate)['ads']);
        $porCanal = $cm->porCanal($de, $ate);
        $this->assertSame(35.0, $porCanal['ferrakora']['ads']);
        $this->assertSame(50.0, $porCanal['loja']['ads']);
        $this->assertSame(35.0, $cm->porItem($de, $ate, 'ferrakora')['canal']['ads']);
    }

    public function test_sem_conta_da_ferrakora_ou_com_a_mesma_conta_nao_grava_nada(): void
    {
        config([
            'services.meta_ads.access_token' => 'token-teste', 'services.meta_ads.page_id' => '456',
            'services.meta_ads.ad_account_id' => '111', 'services.meta_ads.ferrakora_ad_account_id' => 'act_111',
        ]);
        $this->mock(ShopeeAdsService::class);
        $this->mock(MercadoLivreAdsService::class);
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [['date_start' => '2026-11-12', 'spend' => '50.00']]])]);

        $this->artisan('ads:sync-spend', ['--dias' => 1])->assertSuccessful();

        $this->assertSame(0, ChannelAdSpend::query()->where('channel', 'ferrakora')->count());
        $this->assertSame(1, ChannelAdSpend::query()->where('channel', 'meta_ads')->count());
    }
}
