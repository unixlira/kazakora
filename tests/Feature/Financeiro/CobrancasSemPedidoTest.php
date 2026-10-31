<?php

namespace Tests\Feature\Financeiro;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelAdSpend;
use App\Modules\Marketplace\Support\ContributionMargin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Auditoria do financeiro 2026-10-31: Meta e cobranças sem pedido na conta. */
class CobrancasSemPedidoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['loja.simples.aliquota' => 0]);
        Carbon::setTestNow('2026-10-20 12:00:00');
    }

    private function intervalo(): array
    {
        return [Carbon::parse('2026-10-01'), Carbon::parse('2026-11-01')];
    }

    public function test_meta_entra_no_ads_e_vai_para_o_site_na_conta_por_canal(): void
    {
        ChannelAdSpend::query()->create(['channel' => 'meta_ads', 'date' => '2026-10-05', 'spend' => 100, 'impressions' => 1, 'clicks' => 1, 'attributed_orders' => 0, 'attributed_gmv' => 0]);
        [$de, $ate] = $this->intervalo();
        $cm = app(ContributionMargin::class);

        $this->assertSame(100.0, $cm->periodo($de, $ate)['ads']);
        $porCanal = $cm->porCanal($de, $ate);
        $this->assertSame(100.0, $porCanal[Order::ORIGIN_STORE]['ads']);
        $this->assertArrayNotHasKey('meta_ads', $porCanal);
        $this->assertSame(100.0, $cm->porItem($de, $ate, Order::ORIGIN_STORE)['canal']['ads']);
    }

    public function test_lancamentos_e_reembolso_entram_na_taxa_e_a_soma_por_canal_bate(): void
    {
        DB::table('financeiro_lancamentos')->insert([
            ['data' => '2026-10-10', 'canal' => 'mercado_livre', 'tipo' => 'CFRS', 'descricao' => 'Full', 'valor' => 97.98, 'external_id' => 'ml:1'],
            ['data' => '2026-10-10', 'canal' => 'mercado_livre', 'tipo' => 'BESM', 'descricao' => 'estorno', 'valor' => -9.98, 'external_id' => 'ml:2'],
            ['data' => '2026-09-10', 'canal' => 'shopee', 'tipo' => 'ADJUSTMENT_CENTER_DEDUCT', 'descricao' => 'mês anterior', 'valor' => 5, 'external_id' => 'shopee:1'],
        ]);
        DB::table('marketplace_settlement_details')->insert([
            'channel' => 'tiktok_shop', 'statement_id' => 's1', 'status' => 'Pagos', 'transaction_type' => 'Reembolso de logística',
            'external_order_id' => 'x', 'external_sku_id' => '', 'settlement_date' => '2026-10-12', 'payout_amount' => 40, 'source_file' => 'a.xlsx',
        ]);
        [$de, $ate] = $this->intervalo();
        $cm = app(ContributionMargin::class);

        $this->assertSame(['mercado_livre' => 88.0, 'tiktok_shop' => -40.0], ContributionMargin::cobrancasSemPedido($de, $ate));
        $periodo = $cm->periodo($de, $ate);
        $this->assertSame(48.0, $periodo['taxas']);
        $this->assertSame(-48.0, $periodo['lucro']);
        $porCanal = $cm->porCanal($de, $ate);
        $this->assertSame(88.0, $porCanal['mercado_livre']['taxas']);
        $this->assertSame(-40.0, $porCanal['tiktok_shop']['taxas']);
        $this->assertSame(48.0, $cm->porItem($de, $ate)['canal']['cobrancas']);
    }
}
