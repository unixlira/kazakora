<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Admin\Support\Publicidade\CriativosMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tela Criativos (2026-11-19): anúncio a anúncio, Graph API falsa. Gancho,
 * retenção, CPV, tempo assistido em % da duração, total recalculado e selos.
 */
class CriativosMetaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'inertia.testing.ensure_pages_exist' => false,
            'services.meta_ads.access_token' => 'token-kz',
            'services.meta_ads.ad_account_id' => 'act_123',
            'services.meta_ads.page_id' => '999',
            'services.meta_ads.graph_url' => 'https://graph.facebook.com/v23.0',
            'infoprodutos.meta.access_token' => 'token-gk',
            'infoprodutos.meta.ad_account_id' => 'act_1996921014601792',
        ]);
    }

    /** Uma linha de insights de anúncio. */
    private static function linha(string $id, float $gasto, int $impr, int $cliques, int $paginas, int $views3s, int $plays, array $p, int $checkout, int $compras, float $valor, float $tempo): array
    {
        $acoes = fn (int $n) => [['action_type' => 'video_view', 'value' => (string) $n]];

        return [
            'campaign_id' => 'c1', 'campaign_name' => 'Pídelo frio', 'adset_name' => 'Aberto', 'ad_id' => $id, 'ad_name' => "Vídeo {$id}",
            'spend' => (string) $gasto, 'impressions' => (string) $impr, 'clicks' => (string) $cliques, 'inline_link_clicks' => (string) $cliques,
            'actions' => array_merge($acoes($views3s), [
                ['action_type' => 'landing_page_view', 'value' => (string) $paginas],
                ['action_type' => 'initiate_checkout', 'value' => (string) $checkout],
                ['action_type' => 'purchase', 'value' => (string) $compras],
            ]),
            'action_values' => [['action_type' => 'purchase', 'value' => (string) $valor]],
            'video_play_actions' => [['action_type' => 'video_view', 'value' => (string) $plays]],
            'video_p25_watched_actions' => [['action_type' => 'video_view', 'value' => (string) $p[0]]],
            'video_p50_watched_actions' => [['action_type' => 'video_view', 'value' => (string) $p[1]]],
            'video_p75_watched_actions' => [['action_type' => 'video_view', 'value' => (string) $p[2]]],
            'video_p100_watched_actions' => [['action_type' => 'video_view', 'value' => (string) $p[3]]],
            'video_thruplay_watched_actions' => [['action_type' => 'video_view', 'value' => (string) $p[2]]],
            'video_avg_time_watched_actions' => [['action_type' => 'video_view', 'value' => (string) $tempo]],
        ];
    }

    private function fingir(): void
    {
        Http::fake(function (HttpRequest $r) {
            $url = $r->url();
            $dados = $r->data();
            // Cada conta com o próprio token.
            if (str_contains($url, 'act_1996921014601792') || str_contains($url, 'ids=')) {
                $this->assertSame(['Bearer token-gk'], $r->header('Authorization'));
            }

            return match (true) {
                str_contains($url, 'act_1996921014601792/insights') => Http::response(['data' => [
                    // a1: vencedor (melhor CPA); a2: clica e não compra; a3: gancho fraco; a4: segura pouco; a5: pouco dado
                    self::linha('a1', 100, 10000, 150, 100, 3000, 5000, [3000, 2000, 1000, 500], 4, 4, 28, 12),
                    self::linha('a2', 60, 6000, 90, 60, 1800, 3000, [1800, 1200, 600, 300], 3, 0, 0, 11),
                    self::linha('a3', 50, 10000, 50, 40, 500, 4000, [2000, 1400, 700, 350], 0, 1, 7, 10),
                    self::linha('a4', 80, 8000, 120, 80, 2400, 4000, [1500, 600, 300, 100], 0, 1, 7, 4),
                    self::linha('a5', 10, 1000, 5, 4, 300, 500, [100, 50, 10, 5], 0, 0, 0, 3),
                ]]),
                str_contains($url, 'act_1996921014601792') => Http::response(['currency' => 'USD']),
                ($dados['fields'] ?? '') === 'length' => Http::response(collect(['v1', 'v2', 'v3', 'v4', 'v5'])->mapWithKeys(fn ($v) => [$v => ['id' => $v, 'length' => 56.0]])->all()),
                str_contains((string) ($dados['fields'] ?? ''), 'effective_status') => Http::response(collect(['a1', 'a2', 'a3', 'a4', 'a5'])->mapWithKeys(fn ($a, $i) => [$a => [
                    'effective_status' => 'ACTIVE', 'preview_shareable_link' => "https://fb.me/{$a}",
                    'creative' => ['thumbnail_url' => "https://cdn/{$a}.jpg", 'video_id' => 'v'.($i + 1)],
                ]])->all()),
                default => Http::response(['data' => []]),
            };
        });
    }

    public function test_calculos_de_video_cpv_e_selos(): void
    {
        $this->fingir();
        $d = app(CriativosMeta::class)->montar(CriativosMeta::GREENKORA, 'last_7d');

        $this->assertNull($d['erro']);
        $this->assertSame('USD', $d['moeda']);
        $l = collect($d['linhas'])->keyBy('id');

        // a1: gancho = 3000 ÷ 10000; retenção sobre 5000 plays; CPV = 100 ÷ 100.
        $this->assertSame(30.0, $l['a1']['gancho']);
        $this->assertSame(['p25' => 60.0, 'p50' => 40.0, 'p75' => 20.0, 'p100' => 10.0], $l['a1']['retencao']);
        $this->assertSame(1.0, $l['a1']['cpv']);
        $this->assertSame(56.0, $l['a1']['duracao']);
        $this->assertSame(21.4, $l['a1']['tempo_pct']); // 12 s de 56 s
        $this->assertSame(25.0, $l['a1']['cpa']);
        $this->assertSame('https://cdn/a1.jpg', $l['a1']['miniatura']);
        $this->assertSame('https://fb.me/a1', $l['a1']['previa']);

        $this->assertSame('vencedor', $l['a1']['selo']);
        $this->assertSame('clica_nao_compra', $l['a2']['selo']);
        $this->assertSame('gancho_fraco', $l['a3']['selo']);
        $this->assertSame('segura_pouco', $l['a4']['selo']);
        $this->assertSame('pouco_dado', $l['a5']['selo']);

        // Total das somas, não média de médias.
        $t = $d['total'];
        $this->assertSame(300.0, $t['gasto']);
        $this->assertSame(35000, $t['impressoes']);
        $this->assertSame(round(415 / 35000 * 100, 2), $t['ctr_link']);
        $this->assertSame(round(300 / 284, 2), $t['cpv']);
        $this->assertSame(round(8000 / 35000 * 100, 1), $t['gancho']);
        $this->assertSame(round(5250 / 16500 * 100, 1), $t['retencao']['p50']);
        $this->assertSame(6, $t['compras']);
        $this->assertSame(50.0, $t['cpa']);
        $this->assertSame(round(42 / 300, 2), $t['roas']);
    }

    public function test_tela_e_filtro_por_campanha(): void
    {
        $this->fingir();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/admin/publicidade/criativos?conta=greenkora&periodo=last_7d')
            ->assertOk()
            ->assertInertia(fn ($p) => $p->component('Admin/Publicidade/Criativos')->where('conta', 'greenkora')
                ->has('contas.greenkora')->has('contas.kazakora'));

        $vazio = app(CriativosMeta::class)->montar(CriativosMeta::GREENKORA, 'last_7d', '999');
        $this->assertSame([], $vazio['linhas']);
    }
}
