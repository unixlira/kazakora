<?php

namespace Tests\Feature\Shopee;

use App\Models\User;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ShopeeAdsAcao;
use App\Services\Shopee\CaueShopeeAcoes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Estratégia Shopee, botão Ação (tarefa 569): pausar, retomar e meta de
 * ROAS pela API de ADS, com conferência relendo a campanha, simulação com
 * a escrita desligada, travas e desfazer.
 */
class CaueShopeeAcoesTest extends TestCase
{
    use RefreshDatabase;

    /** Estado da campanha 900 "na Shopee", mudado pelas chamadas de edição. */
    private array $campanha = [];

    private array $edicoes = [];

    private bool $aplicaEdicao = true;

    protected function setUp(): void
    {
        parent::setUp();

        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_SHOPEE,
            'status' => MarketplaceAccount::STATUS_CONNECTED,
            'seller_id' => '123456',
            'access_token' => 'fake-access-token',
            'refresh_token' => 'fake-refresh-token',
            'token_expires_at' => now()->addHours(4),
            'connected_at' => now(),
        ]);

        $this->campanha = ['status' => 'ongoing', 'meta' => 4.0, 'tipo' => 'manual', 'lance' => 'auto'];
        $this->fakeShopee();
    }

    private function fakeShopee(): void
    {
        Http::fake(function (Request $r) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            if ($path === '/api/v2/ads/get_product_level_campaign_id_list') {
                return Http::response(['response' => ['campaign_list' => [['campaign_id' => 900], ['campaign_id' => 901]], 'has_next_page' => false]]);
            }
            if ($path === '/api/v2/ads/get_product_level_campaign_setting_info') {
                $pedidas = explode(',', (string) ($r->data()['campaign_id_list'] ?? ''));

                return Http::response(['response' => ['campaign_list' => array_values(array_filter([
                    [
                        'campaign_id' => 900,
                        'common_info' => [
                            'ad_type' => $this->campanha['tipo'], 'ad_name' => 'Caminhão Unicórnio',
                            'campaign_status' => $this->campanha['status'], 'bidding_method' => $this->campanha['lance'],
                            'campaign_budget' => 0, 'item_id_list' => [58266956764],
                        ],
                        'auto_bidding_info' => ['roas_target' => $this->campanha['meta']],
                    ],
                    [
                        'campaign_id' => 901,
                        'common_info' => ['ad_type' => 'manual', 'campaign_status' => 'ongoing', 'bidding_method' => 'auto', 'item_id_list' => [111]],
                    ],
                ], fn ($c) => in_array((string) $c['campaign_id'], $pedidas, true)))]]);
            }
            if (str_starts_with($path, '/api/v2/ads/edit_')) {
                $this->edicoes[] = ['path' => $path, 'body' => $r->data()];
                if ($this->aplicaEdicao) {
                    match ($r->data()['edit_action']) {
                        'pause' => $this->campanha['status'] = 'paused',
                        'resume' => $this->campanha['status'] = 'ongoing',
                        'change_roas_target' => $this->campanha['meta'] = $r->data()['roas_target'],
                    };
                }

                return Http::response(['response' => ['campaign_id' => 900]]);
            }

            return Http::response(['error' => 'not_found', 'message' => $path], 404);
        });
    }

    private function caue(): CaueShopeeAcoes
    {
        return app(CaueShopeeAcoes::class);
    }

    public function test_lista_so_as_campanhas_do_item(): void
    {
        $campanhas = $this->caue()->campanhasDoItem('58266956764');

        $this->assertCount(1, $campanhas);
        $this->assertSame('900', $campanhas[0]['campaign_id']);
        $this->assertSame(4.0, $campanhas[0]['meta']);
    }

    public function test_com_escrita_desligada_so_simula_e_nao_chama_a_shopee(): void
    {
        config(['services.shopee.ads_escrita' => false]);

        $acao = $this->caue()->aplicar('58266956764', '900', ShopeeAdsAcao::ACAO_PAUSAR, null);

        $this->assertSame(ShopeeAdsAcao::STATUS_SIMULADA, $acao->status);
        $this->assertSame([], $this->edicoes);
        $this->assertSame('pause', $acao->resposta['pedido']['edit_action']);
        $this->assertSame('ongoing', $this->campanha['status']);
    }

    public function test_pausa_e_confere_relendo_a_campanha(): void
    {
        config(['services.shopee.ads_escrita' => true]);

        $acao = $this->caue()->aplicar('58266956764', '900', ShopeeAdsAcao::ACAO_PAUSAR, null);

        $this->assertSame(ShopeeAdsAcao::STATUS_CONFERIDA, $acao->status);
        $this->assertSame('/api/v2/ads/edit_manual_product_ads', $this->edicoes[0]['path']);
        $this->assertEquals(['status' => 'ongoing', 'meta' => 4.0, 'tipo' => 'manual', 'lance' => 'auto'], $acao->fresh()->antes);
        $this->assertNotNull($acao->conferida_em);
    }

    public function test_campanha_automatica_da_loja_usa_o_endpoint_auto(): void
    {
        config(['services.shopee.ads_escrita' => true]);
        $this->campanha['tipo'] = 'auto';

        $this->caue()->aplicar('58266956764', '900', ShopeeAdsAcao::ACAO_PAUSAR, null);

        $this->assertSame('/api/v2/ads/edit_auto_product_ads', $this->edicoes[0]['path']);
    }

    public function test_sem_o_valor_novo_na_shopee_fica_nao_conferida(): void
    {
        config(['services.shopee.ads_escrita' => true]);
        $this->aplicaEdicao = false;

        $acao = $this->caue()->aplicar('58266956764', '900', ShopeeAdsAcao::ACAO_PAUSAR, null);

        $this->assertSame(ShopeeAdsAcao::STATUS_NAO_CONFERIDA, $acao->status);
        $this->assertStringContainsString('ongoing', $acao->erro);
    }

    public function test_muda_meta_e_bloqueia_abaixo_do_empate_e_segunda_troca_em_7_dias(): void
    {
        config(['services.shopee.ads_escrita' => true]);

        $acao = $this->caue()->aplicar('58266956764', '900', ShopeeAdsAcao::ACAO_META, null, 9.2, 7.2);
        $this->assertSame(ShopeeAdsAcao::STATUS_CONFERIDA, $acao->status);
        $this->assertSame(9.2, $this->edicoes[0]['body']['roas_target']);

        try {
            $this->caue()->aplicar('58266956764', '900', ShopeeAdsAcao::ACAO_META, null, 10.0, 7.2);
            $this->fail('devia travar por 7 dias');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('7 dias', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->caue()->aplicar('58266956764', '900', ShopeeAdsAcao::ACAO_META, null, 5.0, 7.2, forcar: true);
    }

    public function test_nao_pausa_campanha_ja_pausada_nem_de_outro_item(): void
    {
        $this->campanha['status'] = 'paused';

        try {
            $this->caue()->aplicar('58266956764', '900', ShopeeAdsAcao::ACAO_PAUSAR, null);
            $this->fail('não devia pausar campanha pausada');
        } catch (\InvalidArgumentException) {
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->caue()->aplicar('58266956764', '901', ShopeeAdsAcao::ACAO_RETOMAR, null);
    }

    public function test_desfazer_volta_a_meta_anterior(): void
    {
        config(['services.shopee.ads_escrita' => true]);
        $acao = $this->caue()->aplicar('58266956764', '900', ShopeeAdsAcao::ACAO_META, null, 9.2, 7.2);

        $volta = $this->caue()->desfazer($acao, null);

        $this->assertSame(ShopeeAdsAcao::STATUS_CONFERIDA, $volta->status);
        $this->assertSame(4.0, $this->campanha['meta']);
        $this->assertNotNull($acao->fresh()->desfeita_em);
        $this->assertFalse($acao->fresh()->podeDesfazer());
    }

    public function test_rotas_exigem_permissao_e_aplicam_pelo_clique(): void
    {
        config(['services.shopee.ads_escrita' => false]);
        $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $this->actingAs($cliente)->getJson('/admin/publicidade/shopee/acoes/item/58266956764')->assertForbidden();

        $user = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($user)->getJson('/admin/publicidade/shopee/acoes/item/58266956764?empate=7.2')
            ->assertOk()
            ->assertJsonPath('escrita_ligada', false)
            ->assertJsonPath('meta_sugerida', 9.2)
            ->assertJsonPath('campanhas.0.campaign_id', '900')
            ->assertJsonPath('campanhas.0.pode_meta', true);

        $this->actingAs($user)->postJson('/admin/publicidade/shopee/acoes', [
            'item_id' => '58266956764', 'campaign_id' => '900', 'acao' => 'pausar',
        ])->assertOk()->assertJsonPath('acao.status', 'simulada')->assertJsonPath('historico.0.quem', $user->name);

        $this->actingAs($user)->postJson('/admin/publicidade/shopee/acoes', [
            'item_id' => '58266956764', 'campaign_id' => '900', 'acao' => 'meta_roas', 'meta' => 5, 'roas_empate' => 7.2,
        ])->assertStatus(422);

        $this->assertDatabaseHas('shopee_ads_acoes', ['campaign_id' => '900', 'acao' => 'pausar', 'user_id' => $user->id]);
    }
}
