<?php

namespace Tests\Feature\Financeiro;

use App\Modules\Admin\Models\AlertaAdmin;
use App\Modules\Admin\Support\AlertasAdmin;
use App\Modules\Checkout\Models\Order;
use App\Modules\Financeiro\Support\ResultadoDiario;
use App\Modules\Marketplace\Models\ChannelAdSpend;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\ContributionMargin;
use App\Modules\Marketplace\Support\ImportadorDemonstrativoTikTok;
use App\Services\MercadoLivre\MercadoLivreAdsService;
use App\Services\Meta\MetaAdsService;
use App\Services\Shopee\ShopeeAdsService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Pontos em que o e-mail de lucro e o painel divergiam do dinheiro real
 * (revisão de 2026-11-02): ADS atrasado no e-mail, TikTok em dobro, Meta fora
 * da conta e Shopee Ads falhando em silêncio.
 */
class PontosDeDivergenciaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['loja.simples.aliquota' => 4, 'loja.simples.desde' => '2026-01-01']);
        Carbon::setTestNow('2026-10-20 23:50:00');
    }

    private function pedido(array $extra = []): Order
    {
        return Order::create([
            'status' => Order::STATUS_PAID, 'origin' => 'site',
            'shipping_name' => 'Maria', 'shipping_phone' => '11999990000', 'shipping_zip' => '03187040',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Mooca',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 100, 'discount_amount' => 0, 'shipping_cost' => 0, 'total' => 100,
            ...$extra,
        ]);
    }

    private function gasto(string $canal, float $valor, string $dia = '2026-10-20'): void
    {
        ChannelAdSpend::query()->create(['channel' => $canal, 'date' => $dia, 'spend' => $valor, 'impressions' => 1, 'clicks' => 1, 'attributed_orders' => 0, 'attributed_gmv' => 0]);
    }

    private function horarioNaAgenda(string $comando): array
    {
        return collect(app(Schedule::class)->events())
            ->filter(fn ($evento) => str_contains((string) $evento->command, $comando))
            ->map(fn ($evento) => $evento->expression)->values()->all();
    }

    private function conectar(string $canal): void
    {
        MarketplaceAccount::create(['channel' => $canal, 'status' => MarketplaceAccount::STATUS_CONNECTED, 'seller_id' => '123', 'access_token' => 'fake', 'connected_at' => now()]);
    }

    /** @return \Mockery\MockInterface&ShopeeAdsService */
    private function shopeeAds()
    {
        $this->conectar(MarketplaceAccount::CHANNEL_SHOPEE);
        $mock = Mockery::mock(ShopeeAdsService::class);
        $this->app->instance(ShopeeAdsService::class, $mock);

        return $mock;
    }

    // E-mail de lucro ---------------------------------------------------------

    /** Desde 11/11/2026 o e-mail sai às 07:30 com o dia anterior, depois do anúncio das 06:00. */
    public function test_ads_sincroniza_antes_do_email_da_manha(): void
    {
        $this->assertContains('30 23 * * *', $this->horarioNaAgenda('ads:sync-spend'));
        $this->assertContains('0 6 * * *', $this->horarioNaAgenda('ads:sync-spend'));
        $this->assertSame(['30 7 * * *'], $this->horarioNaAgenda('loja:resultado-diario'));
        $this->assertSame(['50 5 * * *'], $this->horarioNaAgenda('financeiro:lancamentos'));
    }

    public function test_email_painel_e_soma_por_canal_dao_o_mesmo_lucro(): void
    {
        $this->pedido();
        $this->gasto('shopee', 10);
        $this->gasto('meta_ads', 20);
        DB::table('financeiro_lancamentos')->insert(['data' => '2026-10-20', 'canal' => 'mercado_livre', 'tipo' => 'CFRS', 'descricao' => 'Full', 'valor' => 5, 'external_id' => 'f1', 'created_at' => now(), 'updated_at' => now()]);
        [$de, $ate] = [Carbon::parse('2026-10-20'), Carbon::parse('2026-10-21')];
        $cm = app(ContributionMargin::class);

        $painel = $cm->periodo($de, $ate);
        $email = app(ResultadoDiario::class)->calcular(Carbon::parse('2026-10-20'))['dia'];

        $this->assertSame(30.0, $email['anuncios']);
        $this->assertSame($painel['lucro'], $email['lucro']);
        $this->assertSame($painel['lucro'], round(collect($cm->porCanal($de, $ate))->sum('lucro'), 2));
    }

    public function test_imposto_do_email_usa_a_aliquota_da_configuracao(): void
    {
        $this->pedido();

        $resultado = app(ResultadoDiario::class)->calcular(Carbon::parse('2026-10-20'));

        $this->assertSame(4.0, $resultado['aliquota']);
        $this->assertSame(4.0, $resultado['dia']['impostos']);
    }

    // TikTok ------------------------------------------------------------------

    public function test_pedido_pago_com_id_provisorio_e_definitivo_entra_uma_vez_na_receita(): void
    {
        $this->pedido(['origin' => 'tiktok_shop', 'external_order_id' => 'P1']);
        $linha = ['channel' => 'tiktok_shop', 'status' => 'Pagos', 'transaction_type' => 'Pedido', 'external_order_id' => 'P1', 'external_sku_id' => 'S',
            'payment_id' => 'PG1', 'settlement_date' => '2026-10-20', 'product_net_sales' => 49.99, 'payout_amount' => 9.79, 'source_file' => 'a.xlsx'];
        DB::table('marketplace_settlement_details')->insert([
            [...$linha, 'statement_id' => '374', 'created_at' => now()->subDays(4), 'updated_at' => now()->subDays(4)],
            [...$linha, 'statement_id' => '770', 'created_at' => now(), 'updated_at' => now()],
        ]);
        [$de, $ate] = [Carbon::parse('2026-10-20'), Carbon::parse('2026-10-21')];
        $cm = app(ContributionMargin::class);
        $this->assertSame(99.98, $cm->periodo($de, $ate)['receita']);

        app(ImportadorDemonstrativoTikTok::class)->removerLinhasSuperadas();

        $this->assertSame(49.99, $cm->periodo($de, $ate)['receita']);
    }

    public function test_reembolso_do_tiktok_entra_como_credito_e_nao_some_na_soma_por_canal(): void
    {
        DB::table('marketplace_settlement_details')->insert([
            'channel' => 'tiktok_shop', 'statement_id' => 's1', 'status' => 'Pagos', 'transaction_type' => 'Reembolso de logística',
            'external_order_id' => 'x', 'external_sku_id' => '', 'settlement_date' => '2026-10-20', 'payout_amount' => 40, 'source_file' => 'a.xlsx',
        ]);
        [$de, $ate] = [Carbon::parse('2026-10-20'), Carbon::parse('2026-10-21')];
        $cm = app(ContributionMargin::class);

        $this->assertSame(-40.0, $cm->periodo($de, $ate)['taxas']);
        $this->assertSame(-40.0, $cm->porCanal($de, $ate)['tiktok_shop']['taxas']);
    }

    // Meta Ads ----------------------------------------------------------------

    public function test_gasto_diario_da_meta_le_a_conta_inteira_por_dia(): void
    {
        config(['services.meta_ads.ad_account_id' => '123', 'services.meta_ads.access_token' => 'token-teste', 'services.meta_ads.page_id' => '456']);
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [
            ['date_start' => '2026-10-19', 'spend' => '33.50', 'impressions' => '10', 'clicks' => '2'],
            ['date_start' => '2026-10-20', 'spend' => '7.25', 'impressions' => '5', 'clicks' => '1'],
        ]])]);

        $linhas = app(MetaAdsService::class)->gastoDiario(Carbon::parse('2026-10-19'), Carbon::parse('2026-10-20'));

        $this->assertSame(['2026-10-19', '2026-10-20'], array_column($linhas, 'date'));
        $this->assertSame([33.5, 7.25], array_column($linhas, 'spend'));
        Http::assertSent(function ($requisicao) {
            return str_contains($requisicao->url(), 'act_123/insights')
                && $requisicao['level'] === 'account' && (int) $requisicao['time_increment'] === 1;
        });
    }

    public function test_falha_da_meta_vira_alerta_e_nao_impede_a_shopee(): void
    {
        $this->shopeeAds()->shouldReceive('dailyPerformance')->once()->andReturn([
            ['date' => '2026-10-20', 'impressions' => 1, 'clicks' => 1, 'attributed_orders' => 0, 'attributed_gmv' => 0.0, 'spend' => 12.0],
        ]);
        $meta = Mockery::mock(MetaAdsService::class);
        $meta->shouldReceive('configurado')->andReturn(true);
        $meta->shouldReceive('gastoDiario')->once()->andThrow(new RuntimeException('token vencido'));
        $this->app->instance(MetaAdsService::class, $meta);

        $this->artisan('ads:sync-spend')->assertSuccessful();

        $this->assertDatabaseHas('channel_ad_spends', ['channel' => 'shopee', 'date' => '2026-10-20', 'spend' => 12.0]);
        $this->assertTrue(AlertaAdmin::query()->where('tipo', AlertasAdmin::COLETA_FINANCEIRA)->where('mensagem', 'like', '%token vencido%')->exists());
    }

    // Shopee Ads --------------------------------------------------------------

    public function test_shopee_relê_7_dias_e_corrige_dia_parcial(): void
    {
        $this->gasto('shopee', 15.59, '2026-10-15');
        $this->shopeeAds()->shouldReceive('dailyPerformance')->once()
            ->withArgs(fn (Carbon $de, Carbon $ate) => $de->toDateString() === '2026-10-14' && $ate->toDateString() === '2026-10-20')
            ->andReturn([['date' => '2026-10-15', 'impressions' => 1, 'clicks' => 1, 'attributed_orders' => 0, 'attributed_gmv' => 0.0, 'spend' => 112.28]]);

        $this->artisan('ads:sync-spend')->assertSuccessful();

        $this->assertDatabaseHas('channel_ad_spends', ['channel' => 'shopee', 'date' => '2026-10-15', 'spend' => 112.28]);
        $this->assertSame(1, ChannelAdSpend::query()->where('channel', 'shopee')->count());
    }

    public function test_falha_da_shopee_vira_alerta_e_nao_apaga_o_que_ja_estava_gravado(): void
    {
        $this->gasto('shopee', 40.17, '2026-10-19');
        $this->shopeeAds()->shouldReceive('dailyPerformance')->once()->andThrow(new RuntimeException('Shopee fora'));

        $this->artisan('ads:sync-spend')->assertSuccessful();

        $this->assertDatabaseHas('channel_ad_spends', ['channel' => 'shopee', 'date' => '2026-10-19', 'spend' => 40.17]);
        $this->assertTrue(AlertaAdmin::query()->where('tipo', AlertasAdmin::COLETA_FINANCEIRA)->where('mensagem', 'like', '%Shopee fora%')->exists());
    }

    public function test_serie_vazia_da_shopee_vira_alerta(): void
    {
        $this->shopeeAds()->shouldReceive('dailyPerformance')->once()->andReturn([]);

        $this->artisan('ads:sync-spend')->assertSuccessful();

        $this->assertTrue(AlertaAdmin::query()->where('tipo', AlertasAdmin::COLETA_FINANCEIRA)->where('mensagem', 'like', '%vazia%')->exists());
    }
}
