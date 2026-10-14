<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Marketplace\Jobs\EnviarCampanhaMetaAds;
use App\Modules\Marketplace\Models\MetaAdsCampanha;
use App\Services\Meta\MetaAdsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Meta ADS, campanhas de vídeo (2026-10-13): Lira sobe N vídeos e a
 * campanha vai para a Meta com um conjunto de anúncios por vídeo e o
 * orçamento diário dividido igual. Tudo nasce pausado; ativar exige
 * confirmação do gasto.
 */
class MetaAdsCampanhasTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/admin/publicidade/meta-ads/campanhas';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config([
            'services.meta_ads.access_token' => 'token-secreto',
            'services.meta_ads.ad_account_id' => 'act_123',
            'services.meta_ads.page_id' => '999',
            'services.meta_ads.pixel_id' => null,
            'services.meta_ads.graph_url' => 'https://graph.facebook.com/v23.0',
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    /** @return array<string, mixed> */
    private function dados(array $extra = []): array
    {
        return $extra + [
            'nome' => 'Parafusadeira outubro',
            'objetivo' => 'OUTCOME_TRAFFIC',
            'orcamento_diario' => 50,
            'inicio' => now()->addDay()->toDateString(),
            'fim' => null,
            'idade_min' => 25,
            'idade_max' => 55,
            'genero' => 'todos',
            'localizacoes' => ['regioes' => [], 'cidades' => []],
            'produto_id' => null,
            'link_destino' => 'https://kazakora.com/produtos/parafusadeira',
            'texto_principal' => 'Parafusadeira com frete grátis',
            'titulo' => 'Parafusadeira 21V',
            'descricao' => null,
            'chamada' => 'SHOP_NOW',
        ];
    }

    private function campanhaComVideos(int $quantidade, float $orcamento = 50): MetaAdsCampanha
    {
        $campanha = MetaAdsCampanha::create($this->dados(['orcamento_diario' => $orcamento]) + ['status' => MetaAdsCampanha::STATUS_RASCUNHO]);

        for ($i = 0; $i < $quantidade; $i++) {
            $path = "meta-ads/campanha-{$campanha->id}/v{$i}.mp4";
            Storage::disk('local')->put($path, 'conteudo-do-video-'.$i);
            $campanha->videos()->create(['ordem' => $i, 'nome_original' => "video{$i}.mp4", 'path' => $path, 'tamanho' => 1000]);
        }

        return $campanha->load('videos');
    }

    /**
     * Graph API falsa: guarda cada chamada e responde como a Meta.
     *
     * @param  list<HttpRequest>  $chamadas
     */
    private function fakeMeta(array &$chamadas): void
    {
        $videos = 0;
        $adsets = 0;

        Http::fake(function (HttpRequest $request) use (&$chamadas, &$videos, &$adsets) {
            $chamadas[] = $request;
            $caminho = Str::after(parse_url($request->url(), PHP_URL_PATH), '/v23.0/');

            return match (true) {
                $caminho === 'act_123/campaigns' => Http::response(['id' => 'camp1']),
                $caminho === 'act_123/advideos' => Http::response(['id' => 'vid'.(++$videos)]),
                str_ends_with($caminho, '/thumbnails') => Http::response(['data' => [
                    ['uri' => 'https://scontent.example/outra.jpg', 'is_preferred' => false],
                    ['uri' => "https://scontent.example/{$caminho}.jpg", 'is_preferred' => true],
                ]]),
                str_starts_with($caminho, 'vid') => Http::response(['status' => ['video_status' => 'ready']]),
                $caminho === 'act_123/adsets' => Http::response(['id' => 'set'.(++$adsets)]),
                $caminho === 'act_123/adcreatives' => Http::response(['id' => 'cri'.$adsets]),
                $caminho === 'act_123/ads' => Http::response(['id' => 'ad'.$adsets]),
                default => Http::response(['success' => true]),
            };
        });
    }

    private function enviarAteTerminar(MetaAdsCampanha $campanha): void
    {
        $meta = app(MetaAdsService::class);

        for ($i = 0; $i < 20; $i++) {
            if ($meta->avancarEnvio($campanha->fresh('videos')) === MetaAdsService::ENVIO_PRONTO) {
                return;
            }
        }

        $this->fail('O envio não terminou.');
    }

    public function test_divide_o_orcamento_igual_e_o_centavo_que_sobra_vai_para_os_primeiros(): void
    {
        $this->assertSame([1667, 1667, 1666], MetaAdsCampanha::dividirOrcamento(50, 3));
        $this->assertSame([1000, 1000, 1000, 1000, 1000], MetaAdsCampanha::dividirOrcamento(50, 5));
        $this->assertSame([], MetaAdsCampanha::dividirOrcamento(50, 0));
    }

    public function test_salva_rascunho_por_json_e_recebe_os_videos_um_por_um(): void
    {
        $admin = $this->admin();

        $id = $this->actingAs($admin)->postJson(self::BASE, $this->dados())
            ->assertCreated()
            ->json('id');

        foreach (['a.mp4', 'b.mov'] as $nome) {
            $this->actingAs($admin)
                ->post(self::BASE."/{$id}/videos", ['video' => UploadedFile::fake()->create($nome, 2048, str_ends_with($nome, 'mov') ? 'video/quicktime' : 'video/mp4')], ['Accept' => 'application/json'])
                ->assertCreated()
                ->assertJsonPath('video.nome', $nome);
        }

        $campanha = MetaAdsCampanha::with('videos')->findOrFail($id);
        $this->assertSame(MetaAdsCampanha::STATUS_RASCUNHO, $campanha->status);
        $this->assertSame(['a.mp4', 'b.mov'], $campanha->videos->pluck('nome_original')->all());
        $this->assertSame([0, 1], $campanha->videos->pluck('ordem')->all());
        foreach ($campanha->videos as $video) {
            Storage::disk('local')->assertExists($video->path);
        }

        $this->actingAs($admin)->get(self::BASE."/{$id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/MetaAds/Show', false)
                ->has('videos', 2)
                ->where('videos.0.orcamento_diario', 25)
                ->where('videos.1.orcamento_diario', 25));
    }

    public function test_recusa_arquivo_que_nao_e_video(): void
    {
        $campanha = $this->campanhaComVideos(0);

        $this->actingAs($this->admin())
            ->post(self::BASE."/{$campanha->id}/videos", ['video' => UploadedFile::fake()->create('planilha.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('video');

        $this->assertSame(0, $campanha->videos()->count());
    }

    public function test_link_fora_da_loja_e_recusado(): void
    {
        $this->actingAs($this->admin())
            ->postJson(self::BASE, $this->dados(['link_destino' => 'https://kazakora.devlira.com.br/produtos/x']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('link_destino');
    }

    public function test_enviar_poe_na_fila_e_bloqueia_orcamento_baixo_demais_por_video(): void
    {
        Queue::fake();
        $admin = $this->admin();

        $baixo = $this->campanhaComVideos(5, 20); // R$ 4 por vídeo
        $this->actingAs($admin)->post(self::BASE."/{$baixo->id}/enviar")->assertSessionHas('error');
        $this->assertSame(MetaAdsCampanha::STATUS_RASCUNHO, $baixo->fresh()->status);

        $semVideo = $this->campanhaComVideos(0);
        $this->actingAs($admin)->post(self::BASE."/{$semVideo->id}/enviar")->assertSessionHas('error');

        $ok = $this->campanhaComVideos(5, 50);
        $this->actingAs($admin)->post(self::BASE."/{$ok->id}/enviar")->assertSessionHas('success');
        $this->assertSame(MetaAdsCampanha::STATUS_ENVIANDO, $ok->fresh()->status);

        Queue::assertPushed(EnviarCampanhaMetaAds::class, 1);
        Queue::assertPushed(EnviarCampanhaMetaAds::class, fn ($job) => $job->campanhaId === $ok->id);
    }

    public function test_envio_cria_uma_campanha_e_um_conjunto_por_video_com_o_orcamento_dividido_tudo_pausado(): void
    {
        $chamadas = [];
        $this->fakeMeta($chamadas);
        $campanha = $this->campanhaComVideos(3, 50);
        $campanha->forceFill(['status' => MetaAdsCampanha::STATUS_ENVIANDO])->save();

        $this->enviarAteTerminar($campanha);

        $campanha->refresh()->load('videos');
        $this->assertSame(MetaAdsCampanha::STATUS_ENVIADA_PAUSADA, $campanha->status);
        $this->assertSame('camp1', $campanha->meta_campaign_id);
        $this->assertTrue($campanha->envioCompleto());
        $this->assertSame(['vid1', 'vid2', 'vid3'], $campanha->videos->pluck('meta_video_id')->all());
        $this->assertSame('https://scontent.example/vid1/thumbnails.jpg', $campanha->videos[0]->meta_thumbnail_url);
        $this->assertSame([16.67, 16.67, 16.66], $campanha->videos->map(fn ($v) => (float) $v->orcamento_diario)->all());

        $porCaminho = fn (string $fim) => array_values(array_filter($chamadas, fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with(parse_url($r->url(), PHP_URL_PATH), $fim)));

        // Uma campanha só, sem orçamento nela (fica nos conjuntos).
        $this->assertCount(1, $porCaminho('/campaigns'));
        $this->assertSame('PAUSED', $porCaminho('/campaigns')[0]['status']);
        $this->assertSame('false', $porCaminho('/campaigns')[0]['is_adset_budget_sharing_enabled']);

        // Um conjunto por vídeo, cada um com a sua parte em centavos, pausado.
        $conjuntos = $porCaminho('/adsets');
        $this->assertCount(3, $conjuntos);
        $this->assertSame([1667, 1667, 1666], array_map(fn ($r) => (int) $r['daily_budget'], $conjuntos));
        foreach ($conjuntos as $conjunto) {
            $this->assertSame('PAUSED', $conjunto['status']);
            $this->assertSame('camp1', $conjunto['campaign_id']);
        }

        // Criativo de vídeo com a capa da Meta e o link da loja.
        $criativos = $porCaminho('/adcreatives');
        $this->assertCount(3, $criativos);
        $spec = json_decode($criativos[0]['object_story_spec'], true);
        $this->assertSame('vid1', $spec['video_data']['video_id']);
        $this->assertSame('https://scontent.example/vid1/thumbnails.jpg', $spec['video_data']['image_url']);
        $this->assertSame('https://kazakora.com/produtos/parafusadeira', $spec['video_data']['call_to_action']['value']['link']);

        $anuncios = $porCaminho('/ads');
        $this->assertCount(3, $anuncios);
        $this->assertSame(['PAUSED', 'PAUSED', 'PAUSED'], array_map(fn ($r) => $r['status'], $anuncios));

        // Nenhum pedido carrega o token na URL.
        foreach ($chamadas as $chamada) {
            $this->assertStringNotContainsString('token-secreto', $chamada->url());
        }
    }

    public function test_espera_a_meta_processar_o_video_antes_de_criar_o_anuncio(): void
    {
        $chamadas = [];
        Http::fake(function (HttpRequest $request) use (&$chamadas) {
            $chamadas[] = $request;
            $caminho = Str::after(parse_url($request->url(), PHP_URL_PATH), '/v23.0/');

            return match ($caminho) {
                'act_123/campaigns' => Http::response(['id' => 'camp1']),
                'act_123/advideos' => Http::response(['id' => 'vid1']),
                'vid1' => Http::response(['status' => ['video_status' => 'processing']]),
                default => Http::response(['error' => ['message' => 'não devia chamar '.$caminho]], 400),
            };
        });

        $campanha = $this->campanhaComVideos(1, 20);
        $meta = app(MetaAdsService::class);

        $this->assertSame(MetaAdsService::ENVIO_CONTINUAR, $meta->avancarEnvio($campanha->fresh('videos')));
        $this->assertSame(MetaAdsService::ENVIO_AGUARDANDO, $meta->avancarEnvio($campanha->fresh('videos')));
        $this->assertNull($campanha->videos()->first()->meta_adset_id);
    }

    public function test_erro_da_meta_fica_gravado_e_o_reenvio_continua_de_onde_parou(): void
    {
        $falhar = true;
        $campanhasCriadas = 0;
        Http::fake(function (HttpRequest $request) use (&$falhar, &$campanhasCriadas) {
            $caminho = Str::after(parse_url($request->url(), PHP_URL_PATH), '/v23.0/');

            if ($caminho === 'act_123/campaigns') {
                $campanhasCriadas++;

                return Http::response(['id' => 'camp1']);
            }
            if ($caminho === 'act_123/advideos' && $falhar) {
                return Http::response(['error' => ['message' => 'Formato inválido', 'code' => 100]], 400);
            }

            return match (true) {
                $caminho === 'act_123/advideos' => Http::response(['id' => 'vid1']),
                str_ends_with($caminho, '/thumbnails') => Http::response(['data' => [['uri' => 'https://scontent.example/c.jpg']]]),
                $caminho === 'vid1' => Http::response(['status' => ['video_status' => 'ready']]),
                default => Http::response(['id' => 'x'.Str::random(4)]),
            };
        });

        $campanha = $this->campanhaComVideos(1, 20);
        $campanha->forceFill(['status' => MetaAdsCampanha::STATUS_ENVIANDO])->save();

        (new EnviarCampanhaMetaAds($campanha->id))->handle(app(MetaAdsService::class));

        $campanha->refresh();
        $this->assertSame(MetaAdsCampanha::STATUS_ERRO, $campanha->status);
        $this->assertStringContainsString('Formato inválido', $campanha->ultimo_erro);
        $this->assertStringNotContainsString('token-secreto', $campanha->ultimo_erro);

        $falhar = false;
        $campanha->forceFill(['status' => MetaAdsCampanha::STATUS_ENVIANDO])->save();
        $this->enviarAteTerminar($campanha);

        $this->assertSame(1, $campanhasCriadas, 'O reenvio não pode criar outra campanha.');
        $this->assertSame(MetaAdsCampanha::STATUS_ENVIADA_PAUSADA, $campanha->fresh()->status);
    }

    public function test_ativar_exige_confirmacao_e_liga_anuncios_conjuntos_e_campanha(): void
    {
        $chamadas = [];
        $this->fakeMeta($chamadas);
        $campanha = $this->campanhaComVideos(2, 30);
        $this->enviarAteTerminar($campanha);
        $chamadas = [];
        $admin = $this->admin();

        // Sem marcar a confirmação: nada muda.
        $this->actingAs($admin)->post(self::BASE."/{$campanha->id}/ativar", ['orcamento_diario' => 30])
            ->assertSessionHasErrors('confirmo_gasto');
        // Orçamento diferente do que está na tela: nada muda.
        $this->actingAs($admin)->post(self::BASE."/{$campanha->id}/ativar", ['confirmo_gasto' => true, 'orcamento_diario' => 300])
            ->assertSessionHasErrors('orcamento_diario');
        $this->assertSame([], $chamadas);
        $this->assertSame(MetaAdsCampanha::STATUS_ENVIADA_PAUSADA, $campanha->fresh()->status);

        $this->actingAs($admin)->post(self::BASE."/{$campanha->id}/ativar", ['confirmo_gasto' => true, 'orcamento_diario' => 30])
            ->assertSessionHas('success');

        $this->assertSame(MetaAdsCampanha::STATUS_ATIVA, $campanha->fresh()->status);
        $ordem = array_map(fn (HttpRequest $r) => Str::after(parse_url($r->url(), PHP_URL_PATH), '/v23.0/'), $chamadas);
        $this->assertSame(['ad1', 'ad2', 'set1', 'set2', 'camp1'], $ordem);
        foreach ($chamadas as $chamada) {
            $this->assertSame('ACTIVE', $chamada['status']);
        }

        // Pausar desliga da campanha para baixo.
        $chamadas = [];
        $this->actingAs($admin)->post(self::BASE."/{$campanha->id}/pausar")->assertSessionHas('success');
        $ordem = array_map(fn (HttpRequest $r) => Str::after(parse_url($r->url(), PHP_URL_PATH), '/v23.0/'), $chamadas);
        $this->assertSame(['camp1', 'set2', 'set1', 'ad2', 'ad1'], $ordem);
        $this->assertSame(MetaAdsCampanha::STATUS_ENVIADA_PAUSADA, $campanha->fresh()->status);
    }

    public function test_depois_de_enviada_nao_edita_nem_mexe_nos_videos(): void
    {
        $chamadas = [];
        $this->fakeMeta($chamadas);
        $campanha = $this->campanhaComVideos(1, 20);
        $this->enviarAteTerminar($campanha);
        $admin = $this->admin();
        $video = $campanha->videos()->first();

        $this->actingAs($admin)->putJson(self::BASE."/{$campanha->id}", $this->dados(['orcamento_diario' => 999]))->assertStatus(409);
        $this->actingAs($admin)->deleteJson(self::BASE."/{$campanha->id}/videos/{$video->id}")->assertStatus(409);
        $this->actingAs($admin)
            ->post(self::BASE."/{$campanha->id}/videos", ['video' => UploadedFile::fake()->create('c.mp4', 100, 'video/mp4')], ['Accept' => 'application/json'])
            ->assertStatus(409);

        $this->assertSame('20.00', $campanha->fresh()->orcamento_diario);
        $this->assertSame(1, $campanha->videos()->count());
    }

    public function test_excluir_rascunho_apaga_os_arquivos(): void
    {
        $campanha = $this->campanhaComVideos(2);
        $paths = $campanha->videos->pluck('path')->all();

        $this->actingAs($this->admin())->delete(self::BASE."/{$campanha->id}")->assertRedirect(self::BASE);

        $this->assertNull(MetaAdsCampanha::find($campanha->id));
        foreach ($paths as $path) {
            Storage::disk('local')->assertMissing($path);
        }
    }

    public function test_tela_abre_mesmo_sem_meta_configurada(): void
    {
        config(['services.meta_ads.access_token' => null]);

        $this->actingAs($this->admin())->get(self::BASE)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/MetaAds/Index', false)
                ->where('config.configurado', false)
                ->where('config.faltando', ['META_ADS_ACCESS_TOKEN']));

        $this->actingAs($this->admin())->get(self::BASE.'/nova')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/MetaAds/Form', false)->where('limites.max_videos', 10));
    }
}
