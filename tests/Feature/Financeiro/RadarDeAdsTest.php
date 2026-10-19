<?php

namespace Tests\Feature\Financeiro;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Financeiro\Support\RadarDeAds;
use App\Modules\Marketplace\Models\ChannelAdSpend;
use App\Notifications\AdsAcimaDoLimiteNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Alerta de ADS acima de 6% da receita dentro do admin (2026-10-19): mesma
 * regra do vigia do Homer (mês até hoje ou últimos 7 dias, canal com ADS e
 * sem venda também acende), no Fluxo de Caixa, no Resultado e margem e no
 * sino (financeiro:vigiar-ads).
 */
class RadarDeAdsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['loja.simples.aliquota' => 0, 'loja.ads_alerta_percentual' => 6,
            'services.meta_ads.access_token' => null, 'services.meta_ads.ad_account_id' => null, 'services.meta_ads.page_id' => null]);
        $this->travelTo(Carbon::parse('2026-10-19 10:00:00'));
    }

    private function venda(string $canal, float $valor, string $quando): void
    {
        $o = Order::create([
            'status' => Order::STATUS_PAID, 'origin' => $canal, 'shipping_name' => 'Cliente', 'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000', 'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP', 'subtotal' => $valor, 'total' => $valor,
        ]);
        $o->forceFill(['created_at' => $quando])->save();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_regra_do_mes_e_dos_7_dias(): void
    {
        // Shopee: mês 3% (ok), últimos 7 dias 10% (acima).
        $this->venda('shopee', 1000.5, '2026-10-02 10:00:00');
        $this->venda('shopee', 100.25, '2026-10-18 10:00:00');
        ChannelAdSpend::create(['date' => '2026-10-17', 'channel' => 'shopee', 'spend' => 10.25]);
        ChannelAdSpend::create(['date' => '2026-10-02', 'channel' => 'shopee', 'spend' => 22.5]);
        // Mercado Livre: 4% nos dois (ok).
        $this->venda('mercado_livre', 500.5, '2026-10-18 10:00:00');
        ChannelAdSpend::create(['date' => '2026-10-18', 'channel' => 'mercado_livre', 'spend' => 20.02]);
        // TikTok: ADS sem venda nenhuma.
        ChannelAdSpend::create(['date' => '2026-10-18', 'channel' => 'tiktok_shop', 'spend' => 5.5]);

        $r = app(RadarDeAds::class)->calcular();
        $c = collect($r['canais'])->keyBy('canal');

        $this->assertSame(['shopee', 'tiktok_shop'], array_column($r['acima'], 'canal'));
        $this->assertFalse($c['shopee']['mes']['acima']);
        $this->assertTrue($c['shopee']['semana']['acima']);
        $this->assertSame(10.22, $c['shopee']['semana']['pct']);
        $this->assertFalse($c['mercado_livre']['acima']);
        $this->assertNull($c['tiktok_shop']['mes']['pct']);
        $this->assertSame('/admin/publicidade/shopee', $c['shopee']['link']['href']);
        $this->assertSame(['de' => '2026-10-13', 'ate' => '2026-10-19'], $r['semana']);
        // Meta não configurada: fora da lista, com o motivo.
        $this->assertFalse($r['meta']['disponivel']);
        $this->assertFalse($c->has('meta_ads'));
    }

    public function test_meta_ads_usa_o_gasto_da_api_contra_a_receita_do_site(): void
    {
        config(['services.meta_ads.access_token' => 'tk', 'services.meta_ads.ad_account_id' => '123', 'services.meta_ads.page_id' => '9']);
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [[
            'spend' => '30.50', 'action_values' => [['action_type' => 'omni_purchase', 'value' => '120.00']],
        ]]])]);
        $this->venda('loja', 300.25, '2026-10-18 10:00:00');

        $r = app(RadarDeAds::class)->calcular();
        $meta = collect($r['canais'])->firstWhere('canal', 'meta_ads');

        $this->assertTrue($r['meta']['disponivel']);
        $this->assertSame(30.5, $meta['mes']['ads']);
        $this->assertSame(10.16, $meta['mes']['pct']);
        $this->assertTrue($meta['acima']);
        $this->assertSame('/admin/publicidade/meta-ads', $meta['link']['href']);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'act_123/insights') && str_contains(urldecode($req->url()), '"since":"2026-10-01"'));
    }

    public function test_meta_fora_do_ar_nao_derruba_a_tela(): void
    {
        config(['services.meta_ads.access_token' => 'tk', 'services.meta_ads.ad_account_id' => '123', 'services.meta_ads.page_id' => '9']);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'boom', 'code' => 1]], 500)]);

        $this->actingAs($this->admin())->get('/admin/dashboard-financeiro')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('radarAds.meta.disponivel', false)
                ->where('radarAds.meta.motivo', 'A Meta não respondeu agora.')
                ->where('anuncios.linhas', fn ($l) => collect($l)->firstWhere('canal', 'meta_ads')['gasto'] === null));
    }

    public function test_alerta_aparece_no_fluxo_de_caixa_sem_repetir_o_alerta_do_periodo(): void
    {
        $this->venda('shopee', 100.25, '2026-10-18 10:00:00');
        ChannelAdSpend::create(['date' => '2026-10-18', 'channel' => 'shopee', 'spend' => 12.5]);

        $this->actingAs($this->admin())->get('/admin/fluxo-de-caixa')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('radarAds.acima.0.canal', 'shopee')
                ->where('alertas', fn ($alertas) => ! collect($alertas)->contains('chave', 'ads:shopee')));
    }

    public function test_sino_avisa_uma_vez_por_dia_e_avisa_quando_volta(): void
    {
        $admin = $this->admin();
        $this->venda('shopee', 100.25, '2026-10-18 10:00:00');
        $ads = ChannelAdSpend::create(['date' => '2026-10-18', 'channel' => 'shopee', 'spend' => 12.5]);

        $this->artisan('financeiro:vigiar-ads')->assertSuccessful();
        $this->assertSame(1, $admin->notifications()->count());
        $n = $admin->notifications()->first();
        $this->assertSame(AdsAcimaDoLimiteNotification::class, $n->type);
        $this->assertSame('ADS Shopee passou de 6% da receita', $n->data['message']);
        $this->assertStringContainsString('12,5%', $n->data['body']);
        $this->assertSame('/admin/publicidade/shopee', $n->data['link']);

        // Mesma hora e dia: não repete.
        $this->travel(2)->hours();
        $this->artisan('financeiro:vigiar-ads')->assertSuccessful();
        $this->assertSame(1, $admin->notifications()->count());

        // Voltou para baixo de 6%.
        $ads->update(['spend' => 2.5]);
        $this->artisan('financeiro:vigiar-ads')->assertSuccessful();
        $this->assertSame(2, $admin->notifications()->count());
        $this->assertSame('ADS Shopee voltou para 6% da receita ou menos', $admin->notifications()->latest()->first()->data['message']);

        // --seco não grava nada.
        $ads->update(['spend' => 50.5]);
        $this->artisan('financeiro:vigiar-ads --seco')->assertSuccessful();
        $this->assertSame(2, $admin->notifications()->count());
    }
}
