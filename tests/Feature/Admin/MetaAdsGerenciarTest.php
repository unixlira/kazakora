<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Marketplace\Models\MetaAdsCampanha;
use App\Modules\Marketplace\Models\MetaAdsConjunto;
use App\Modules\Marketplace\Models\MetaAdsVideo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Meta ADS (2026-10-20): editar e excluir campanha, conjunto e anúncio pelo
 * id da Meta, inclusive os criados direto no Gerenciador. Tudo contra a
 * Graph API falsa (Http::fake): nada real é tocado.
 */
class MetaAdsGerenciarTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/admin/publicidade/meta-ads/gerenciar';

    /** @var list<HttpRequest> */
    private array $chamadas = [];

    /** Objetos que a Meta devolve no GET {id}. */
    private array $objetos = [];

    /** Conjuntos ativos da campanha 1001 e anúncios ativos do conjunto 2001. */
    private array $ativos = ['1001/adsets' => ['2001', '2002'], '2001/ads' => ['3001', '3002']];

    private ?array $erroNoPost = null;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config([
            'services.meta_ads.access_token' => 'token-secreto',
            'services.meta_ads.ad_account_id' => 'act_123',
            'services.meta_ads.page_id' => '999',
            'services.meta_ads.graph_url' => 'https://graph.facebook.com/v23.0',
        ]);

        $this->objetos = [
            '1001' => ['id' => '1001', 'name' => 'Black November', 'account_id' => '123', 'effective_status' => 'ACTIVE', 'daily_budget' => '3000'],
            '2001' => ['id' => '2001', 'name' => 'Black November | Natal', 'account_id' => '123', 'campaign_id' => '1001', 'effective_status' => 'ACTIVE'],
            '3009' => ['id' => '3009', 'name' => 'Feito no Gerenciador', 'account_id' => '123', 'campaign_id' => '1001', 'adset_id' => '2001', 'effective_status' => 'ACTIVE'],
            '3001' => ['id' => '3001', 'name' => 'Do KazaKora', 'account_id' => '123', 'campaign_id' => '1001', 'adset_id' => '2001', 'effective_status' => 'ACTIVE'],
            '9999' => ['id' => '9999', 'name' => 'De outra conta', 'account_id' => '777', 'effective_status' => 'ACTIVE'],
        ];

        Http::fake(function (HttpRequest $request) {
            $this->chamadas[] = $request;
            $caminho = Str::after(parse_url($request->url(), PHP_URL_PATH), '/v23.0/');

            if ($request->method() === 'POST') {
                return $this->erroNoPost ? Http::response($this->erroNoPost, 400) : Http::response(['success' => true]);
            }

            if (isset($this->ativos[$caminho])) {
                return Http::response(['data' => array_map(fn ($id) => ['id' => $id, 'effective_status' => 'ACTIVE'], $this->ativos[$caminho])]);
            }

            return isset($this->objetos[$caminho])
                ? Http::response($this->objetos[$caminho])
                : Http::response(['error' => ['message' => 'Objeto não existe']], 404);
        });
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    /** @return list<array{0: string, 1: array}> */
    private function posts(): array
    {
        return array_values(array_map(
            fn (HttpRequest $r) => [Str::after(parse_url($r->url(), PHP_URL_PATH), '/v23.0/'), $r->data()],
            array_filter($this->chamadas, fn (HttpRequest $r) => $r->method() === 'POST'),
        ));
    }

    /** Campanha do KazaKora já na Meta (1001 > 2001 > 3001), orçamento da campanha. */
    private function campanhaLocal(string $orcamentoTipo = MetaAdsCampanha::ORCAMENTO_CAMPANHA): MetaAdsCampanha
    {
        $campanha = MetaAdsCampanha::create([
            'nome' => 'Black November', 'objetivo' => 'OUTCOME_TRAFFIC', 'orcamento_tipo' => $orcamentoTipo, 'orcamento_diario' => 30,
            'inicio' => now()->toDateString(), 'idade_min' => 25, 'idade_max' => 55, 'genero' => 'todos',
            'link_destino' => 'https://kazakora.com/produtos/arvore', 'chamada' => 'SHOP_NOW',
            'status' => MetaAdsCampanha::STATUS_ATIVA, 'meta_campaign_id' => '1001',
        ]);
        $conjunto = $campanha->conjuntos()->create(['nome' => 'Natal', 'ordem' => 0, 'meta_adset_id' => '2001', 'orcamento_diario' => $orcamentoTipo === 'conjunto' ? 30 : null]);
        Storage::disk('local')->put("meta-ads/campanha-{$campanha->id}/v0.mp4", 'video');
        $campanha->videos()->create([
            'conjunto_id' => $conjunto->id, 'ordem' => 0, 'nome_original' => 'v0.mp4', 'path' => "meta-ads/campanha-{$campanha->id}/v0.mp4",
            'tamanho' => 1000, 'meta_adset_id' => '2001', 'meta_ad_id' => '3001', 'meta_creative_id' => '5001', 'meta_video_id' => '4001',
        ]);

        return $campanha->fresh(['conjuntos', 'videos']);
    }

    public function test_renomeia_campanha_do_gerenciador_so_com_o_nome(): void
    {
        $this->actingAs($this->admin())
            ->putJson(self::BASE.'/campanha/1001', ['nome' => 'Black November 2'])
            ->assertOk();

        $this->assertSame([['1001', ['name' => 'Black November 2']]], $this->posts());
    }

    public function test_mudar_orcamento_exige_confirmar_o_gasto(): void
    {
        $campanha = $this->campanhaLocal();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->putJson(self::BASE.'/campanha/1001', ['nome' => 'Black November', 'orcamento_diario' => 50])
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirmo_gasto');
        $this->assertSame([], $this->posts());

        $this->actingAs($admin)
            ->putJson(self::BASE.'/campanha/1001', ['nome' => 'Black November', 'orcamento_diario' => 50, 'confirmo_gasto' => true])
            ->assertOk();

        $this->assertSame([['1001', ['name' => 'Black November', 'daily_budget' => '5000']]], array_map(
            fn ($p) => [$p[0], array_map('strval', $p[1])],
            $this->posts(),
        ));
        $this->assertSame('50.00', $campanha->fresh()->orcamento_diario);
    }

    public function test_orcamento_que_nao_e_diario_no_nivel_e_recusado(): void
    {
        $this->actingAs($this->admin())
            ->putJson(self::BASE.'/conjunto/2001', ['nome' => 'Natal', 'orcamento_diario' => 20, 'confirmo_gasto' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('orcamento_diario');

        $this->assertSame([], $this->posts());
    }

    public function test_renomear_conjunto_acompanha_no_kazakora_sem_o_prefixo_da_campanha(): void
    {
        $campanha = $this->campanhaLocal();

        $this->actingAs($this->admin())
            ->putJson(self::BASE.'/conjunto/2001', ['nome' => 'Black November | Natal grande'])
            ->assertOk();

        $this->assertSame('Natal grande', $campanha->conjuntos()->first()->nome);
    }

    public function test_orcamento_do_conjunto_atualiza_a_soma_da_campanha(): void
    {
        $this->objetos['2001']['daily_budget'] = '3000';
        $campanha = $this->campanhaLocal(MetaAdsCampanha::ORCAMENTO_CONJUNTO);

        $this->actingAs($this->admin())
            ->putJson(self::BASE.'/conjunto/2001', ['nome' => 'Black November | Natal', 'orcamento_diario' => 45, 'confirmo_gasto' => true])
            ->assertOk();

        $this->assertSame('45.00', $campanha->conjuntos()->first()->orcamento_diario);
        $this->assertSame('45.00', $campanha->fresh()->orcamento_diario);
    }

    public function test_item_de_outra_conta_nao_e_tocado(): void
    {
        $this->actingAs($this->admin())
            ->deleteJson(self::BASE.'/campanha/9999', ['confirmo_exclusao' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'não é da conta'));

        $this->actingAs($this->admin())
            ->deleteJson(self::BASE.'/campanha/12345', ['confirmo_exclusao' => true])
            ->assertStatus(422);

        $this->assertSame([], $this->posts());
    }

    public function test_excluir_exige_confirmar_que_e_definitivo(): void
    {
        $this->actingAs($this->admin())
            ->deleteJson(self::BASE.'/campanha/1001')
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirmo_exclusao');

        $this->assertSame([], $this->posts());
    }

    public function test_excluir_campanha_marca_deleted_e_guarda_o_historico(): void
    {
        $campanha = $this->campanhaLocal();

        $this->actingAs($this->admin())
            ->deleteJson(self::BASE.'/campanha/1001', ['confirmo_exclusao' => true])
            ->assertOk();

        $this->assertSame([['1001', ['status' => 'DELETED']]], $this->posts());
        $this->assertSoftDeleted($campanha);
        $this->assertSoftDeleted(MetaAdsConjunto::withTrashed()->first());
        $this->assertSoftDeleted(MetaAdsVideo::withTrashed()->first());
        Storage::disk('local')->assertMissing("meta-ads/campanha-{$campanha->id}/v0.mp4");
        $this->assertSame(0, MetaAdsCampanha::count());
    }

    public function test_unico_conjunto_ativo_pede_confirmacao_extra(): void
    {
        $this->ativos['1001/adsets'] = ['2001'];
        $campanha = $this->campanhaLocal();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->deleteJson(self::BASE.'/conjunto/2001', ['confirmo_exclusao' => true])
            ->assertStatus(422)
            ->assertJsonPath('ultimo_ativo', true);
        $this->assertSame([], $this->posts());

        $this->actingAs($admin)
            ->deleteJson(self::BASE.'/conjunto/2001', ['confirmo_exclusao' => true, 'sem_ativo' => true])
            ->assertOk();

        $this->assertSame([['2001', ['status' => 'DELETED']]], $this->posts());
        $this->assertSame(0, $campanha->conjuntos()->count());
        $this->assertSame(0, $campanha->videos()->count());
        $this->assertNotSoftDeleted($campanha);
    }

    public function test_anuncio_do_gerenciador_renomeia_e_exclui(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->putJson(self::BASE.'/anuncio/3009', ['nome' => 'Novo nome'])->assertOk();
        $this->actingAs($admin)->deleteJson(self::BASE.'/anuncio/3009', ['confirmo_exclusao' => true])->assertOk();

        $this->assertSame([['3009', ['name' => 'Novo nome']], ['3009', ['status' => 'DELETED']]], $this->posts());
    }

    public function test_anuncio_do_kazakora_usa_o_editar_proprio(): void
    {
        $this->campanhaLocal();

        $this->actingAs($this->admin())
            ->deleteJson(self::BASE.'/anuncio/3001', ['confirmo_exclusao' => true])
            ->assertStatus(409);

        $this->assertSame([], $this->posts());
    }

    public function test_campanha_enviando_bloqueia(): void
    {
        $this->campanhaLocal()->forceFill(['status' => MetaAdsCampanha::STATUS_ENVIANDO])->save();

        $this->actingAs($this->admin())
            ->putJson(self::BASE.'/campanha/1001', ['nome' => 'Outro'])
            ->assertStatus(409);

        $this->assertSame([], $this->posts());
    }

    public function test_meta_recusa_e_nada_muda_no_kazakora(): void
    {
        $campanha = $this->campanhaLocal();
        $this->erroNoPost = ['error' => ['message' => 'Campanha em análise']];

        $this->actingAs($this->admin())
            ->deleteJson(self::BASE.'/campanha/1001', ['confirmo_exclusao' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Campanha em análise'));

        $this->assertNotSoftDeleted($campanha);
        Storage::disk('local')->assertExists("meta-ads/campanha-{$campanha->id}/v0.mp4");
    }

    public function test_rascunho_ainda_sai_de_vez(): void
    {
        $campanha = MetaAdsCampanha::create([
            'nome' => 'Rascunho', 'objetivo' => 'OUTCOME_TRAFFIC', 'orcamento_diario' => 30, 'inicio' => now()->toDateString(),
            'idade_min' => 25, 'idade_max' => 55, 'genero' => 'todos', 'link_destino' => 'https://kazakora.com', 'chamada' => 'SHOP_NOW',
            'status' => MetaAdsCampanha::STATUS_RASCUNHO,
        ]);

        $this->actingAs($this->admin())->delete('/admin/publicidade/meta-ads/campanhas/'.$campanha->id)->assertRedirect();

        $this->assertSame(0, MetaAdsCampanha::withTrashed()->count());
    }

    public function test_sem_permissao_nao_mexe(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->deleteJson(self::BASE.'/campanha/1001', ['confirmo_exclusao' => true])
            ->assertForbidden();

        $this->assertSame([], $this->posts());
    }

    public function test_pausar_criativo_do_gerenciador(): void
    {
        $this->actingAs($this->admin())
            ->postJson(self::BASE.'/anuncio/3009/pausar')
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame([['3009', ['status' => 'PAUSED']]], $this->posts());
    }

    public function test_pausar_criativo_do_kazakora_limpa_o_ativado_em(): void
    {
        $campanha = $this->campanhaLocal();
        $video = $campanha->videos->first();
        $video->forceFill(['ativado_em' => now()])->save();

        $this->actingAs($this->admin())
            ->postJson(self::BASE.'/anuncio/3001/pausar')
            ->assertOk();

        $this->assertSame([['3001', ['status' => 'PAUSED']]], $this->posts());
        $this->assertNull($video->fresh()->ativado_em);
    }

    public function test_pausar_ultimo_anuncio_ativo_pede_confirmacao_extra(): void
    {
        $this->ativos['2001/ads'] = ['3009'];
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson(self::BASE.'/anuncio/3009/pausar')
            ->assertStatus(422)
            ->assertJson(['ultimo_ativo' => true]);
        $this->assertSame([], $this->posts());

        $this->actingAs($admin)
            ->postJson(self::BASE.'/anuncio/3009/pausar', ['sem_ativo' => true])
            ->assertOk();
        $this->assertSame([['3009', ['status' => 'PAUSED']]], $this->posts());
    }

    public function test_pausar_anuncio_que_nao_esta_ativo_nao_mexe(): void
    {
        $this->objetos['3009']['effective_status'] = 'PAUSED';

        $this->actingAs($this->admin())
            ->postJson(self::BASE.'/anuncio/3009/pausar')
            ->assertStatus(409);

        $this->assertSame([], $this->posts());
    }

    public function test_pausar_anuncio_de_outra_conta_e_sem_permissao_nao_mexe(): void
    {
        $this->actingAs($this->admin())
            ->postJson(self::BASE.'/anuncio/9999/pausar')
            ->assertStatus(422);

        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->postJson(self::BASE.'/anuncio/3009/pausar')
            ->assertForbidden();

        $this->assertSame([], $this->posts());
    }
}
