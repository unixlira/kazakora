<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Jobs\EnviarCampanhaMetaAds;
use App\Modules\Marketplace\Models\MetaAdsCampanha;
use App\Modules\Marketplace\Models\MetaAdsConjunto;
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
 * Meta ADS (2026-10-13; conjuntos em 2026-10-18): Lira monta a campanha
 * como no Gerenciador, Campanha > vários conjuntos > vários anúncios, e ela
 * vai para a Meta com o orçamento na campanha ou em cada conjunto. Tudo
 * nasce pausado; ativar exige confirmação do gasto.
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

    /**
     * Campanha com N vídeos. Sem $conjuntos, um conjunto por vídeo; com,
     * os vídeos se repartem entre os conjuntos na ordem.
     */
    private function campanhaComVideos(int $quantidade, float $orcamento = 50, ?int $conjuntos = null, string $tipo = MetaAdsCampanha::ORCAMENTO_CAMPANHA): MetaAdsCampanha
    {
        $campanha = MetaAdsCampanha::create($this->dados(['orcamento_diario' => $orcamento, 'orcamento_tipo' => $tipo]) + ['status' => MetaAdsCampanha::STATUS_RASCUNHO]);
        $conjuntos ??= max($quantidade, 1);
        $partes = MetaAdsCampanha::dividirOrcamento($orcamento, $conjuntos);

        $criados = collect(range(0, $conjuntos - 1))->map(fn (int $i) => $campanha->conjuntos()->create([
            'nome' => 'Conjunto '.($i + 1), 'ordem' => $i, 'orcamento_diario' => $partes[$i] / 100,
        ]));

        for ($i = 0; $i < $quantidade; $i++) {
            $path = "meta-ads/campanha-{$campanha->id}/v{$i}.mp4";
            Storage::disk('local')->put($path, 'conteudo-do-video-'.$i);
            $campanha->videos()->create([
                'conjunto_id' => $criados[intdiv($i * $conjuntos, max($quantidade, 1))]->id,
                'ordem' => $i, 'nome_original' => "video{$i}.mp4", 'path' => $path, 'tamanho' => 1000,
            ]);
        }

        return $campanha->load(['conjuntos', 'videos']);
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
        $imagens = 0;
        $criativos = 0;
        $ads = 0;

        Http::fake(function (HttpRequest $request) use (&$chamadas, &$videos, &$adsets, &$imagens, &$criativos, &$ads) {
            $chamadas[] = $request;
            $caminho = Str::after(parse_url($request->url(), PHP_URL_PATH), '/v23.0/');

            return match (true) {
                $caminho === 'act_123/campaigns' => Http::response(['id' => 'camp1']),
                $caminho === 'act_123/advideos' => Http::response(['id' => 'vid'.(++$videos)]),
                $caminho === 'act_123/adimages' => Http::response(['images' => ['arvore.jpg' => ['hash' => 'hash'.(++$imagens), 'url' => "https://scontent.example/img{$imagens}.jpg"]]]),
                str_ends_with($caminho, '/thumbnails') => Http::response(['data' => [
                    ['uri' => 'https://scontent.example/outra.jpg', 'is_preferred' => false],
                    ['uri' => "https://scontent.example/{$caminho}.jpg", 'is_preferred' => true],
                ]]),
                str_starts_with($caminho, 'vid') => Http::response(['status' => ['video_status' => 'ready']]),
                $caminho === 'act_123/adsets' => Http::response(['id' => 'set'.(++$adsets)]),
                $caminho === 'act_123/adcreatives' => Http::response(['id' => 'cri'.(++$criativos)]),
                $caminho === 'act_123/ads' => Http::response(['id' => 'ad'.(++$ads)]),
                default => Http::response(['success' => true]),
            };
        });
    }

    private function enviarAteTerminar(MetaAdsCampanha $campanha): void
    {
        $meta = app(MetaAdsService::class);

        for ($i = 0; $i < 20; $i++) {
            if ($meta->avancarEnvio($campanha->fresh(['conjuntos', 'videos'])) === MetaAdsService::ENVIO_PRONTO) {
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

        // Sem conjuntos na tela, a campanha ganha o "Conjunto 1" com tudo dentro.
        $this->assertSame(['Conjunto 1'], $campanha->conjuntos()->pluck('nome')->all());
        $this->assertSame([$campanha->conjuntos()->value('id')], $campanha->videos->pluck('conjunto_id')->unique()->values()->all());

        $this->actingAs($admin)->get(self::BASE."/{$id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/MetaAds/Show', false)
                ->has('videos', 2)
                ->has('conjuntos', 1));
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

    public function test_orcamento_por_conjunto_cria_cada_conjunto_com_o_seu_valor_tudo_pausado(): void
    {
        $chamadas = [];
        $this->fakeMeta($chamadas);
        $campanha = $this->campanhaComVideos(3, 50, null, MetaAdsCampanha::ORCAMENTO_CONJUNTO);
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
        $this->assertArrayNotHasKey('daily_budget', $porCaminho('/campaigns')[0]->data());

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

    /**
     * Pedido do Lira (2026-10-18): 3 árvores de Natal diferentes no mesmo
     * conjunto, cada uma com a sua mídia, produto e texto.
     */
    public function test_conjunto_com_varios_anuncios_cada_um_com_o_seu_texto(): void
    {
        $chamadas = [];
        $this->fakeMeta($chamadas);
        $campanha = MetaAdsCampanha::create($this->dados([
            'nome' => 'Natal',
            'orcamento_diario' => 30,
            'texto_principal' => 'Monte o seu Natal',
            'titulo' => null,
        ]) + ['status' => MetaAdsCampanha::STATUS_RASCUNHO]);
        $conjunto = $campanha->conjuntos()->create(['nome' => 'Árvores', 'ordem' => 0]);

        foreach ([['arvore1.jpg', 'imagem', 'Árvore 1,80 m'], ['arvore2.mp4', 'video', 'Árvore 2,10 m'], ['arvore3.png', 'imagem', 'Árvore nevada']] as $i => [$nome, $tipo, $titulo]) {
            $path = "meta-ads/campanha-{$campanha->id}/{$nome}";
            Storage::disk('local')->put($path, 'conteudo-'.$i);
            $campanha->videos()->create([
                'conjunto_id' => $conjunto->id,
                'ordem' => $i, 'tipo' => $tipo, 'nome_original' => $nome, 'path' => $path, 'tamanho' => 1000,
                'titulo' => $titulo, 'link_destino' => "https://kazakora.com/produtos/arvore-{$i}",
                'texto_principal' => $i === 2 ? 'Árvore nevada com 500 galhos' : null,
            ]);
        }

        $this->assertSame([], app(MetaAdsService::class)->problemasParaEnviar($campanha->fresh(['conjuntos', 'videos'])));

        $campanha->forceFill(['status' => MetaAdsCampanha::STATUS_ENVIANDO])->save();
        $this->enviarAteTerminar($campanha);

        $campanha->refresh()->load(['conjuntos', 'videos']);
        $this->assertSame(MetaAdsCampanha::STATUS_ENVIADA_PAUSADA, $campanha->status);
        $this->assertSame('set1', $campanha->conjuntos[0]->meta_adset_id);
        $this->assertSame(['set1', 'set1', 'set1'], $campanha->videos->pluck('meta_adset_id')->all());
        $this->assertSame(['hash1', null, 'hash2'], $campanha->videos->pluck('meta_image_hash')->all());

        $porCaminho = fn (string $fim) => array_values(array_filter($chamadas, fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with(parse_url($r->url(), PHP_URL_PATH), $fim)));

        // Orçamento da campanha (Advantage): verba na campanha, conjunto sem orçamento.
        $this->assertSame(3000, (int) $porCaminho('/campaigns')[0]['daily_budget']);
        $this->assertSame('LOWEST_COST_WITHOUT_CAP', $porCaminho('/campaigns')[0]['bid_strategy']);
        $this->assertArrayNotHasKey('is_adset_budget_sharing_enabled', $porCaminho('/campaigns')[0]->data());
        $conjuntos = $porCaminho('/adsets');
        $this->assertCount(1, $conjuntos);
        $this->assertArrayNotHasKey('daily_budget', $conjuntos[0]->data());
        $this->assertArrayNotHasKey('bid_strategy', $conjuntos[0]->data());
        $this->assertSame('Natal | Árvores', $conjuntos[0]['name']);

        // Imagem: link_data com o hash; vídeo: video_data. Cada um com o seu título e link.
        $specs = array_map(fn ($r) => json_decode($r['object_story_spec'], true), $porCaminho('/adcreatives'));
        $this->assertCount(3, $specs);
        $this->assertSame('hash1', $specs[0]['link_data']['image_hash']);
        $this->assertSame('Árvore 1,80 m', $specs[0]['link_data']['name']);
        $this->assertSame('https://kazakora.com/produtos/arvore-0', $specs[0]['link_data']['link']);
        $this->assertSame('Monte o seu Natal', $specs[0]['link_data']['message']);
        $this->assertSame('vid1', $specs[1]['video_data']['video_id']);
        $this->assertSame('Árvore 2,10 m', $specs[1]['video_data']['title']);
        $this->assertSame('https://kazakora.com/produtos/arvore-1', $specs[1]['video_data']['call_to_action']['value']['link']);
        $this->assertSame('Árvore nevada com 500 galhos', $specs[2]['link_data']['message']);

        $anuncios = $porCaminho('/ads');
        $this->assertCount(3, $anuncios);
        $this->assertSame(['set1', 'set1', 'set1'], array_map(fn ($r) => $r['adset_id'], $anuncios));

        // Ativar liga cada objeto uma vez só (o conjunto é o mesmo para todos).
        $chamadas = [];
        app(MetaAdsService::class)->alterarStatus($campanha, 'ACTIVE');
        $ordem = array_map(fn (HttpRequest $r) => Str::after(parse_url($r->url(), PHP_URL_PATH), '/v23.0/'), $chamadas);
        $this->assertSame(['ad1', 'ad2', 'ad3', 'set1', 'camp1'], $ordem);
    }

    /**
     * Cenário do Lira (2026-10-18): Black November com 3 conjuntos (Árvore
     * de Natal com 3 anúncios, Ferramentas com 2, Caixa de Ferramentas com
     * 2), montada pela tela e enviada de uma vez.
     */
    public function test_black_november_com_tres_conjuntos_e_sete_anuncios(): void
    {
        $chamadas = [];
        $this->fakeMeta($chamadas);
        $admin = $this->admin();

        $resposta = $this->actingAs($admin)->postJson(self::BASE, $this->dados([
            'nome' => 'Black November',
            'orcamento_diario' => 60,
            'conjuntos' => [
                ['chave' => 'a', 'nome' => 'Árvore de Natal', 'publico_proprio' => false],
                ['chave' => 'b', 'nome' => 'Ferramentas', 'publico_proprio' => true, 'idade_min' => 30, 'idade_max' => 60, 'genero' => 'masculino',
                    'localizacoes' => ['regioes' => [['key' => '460', 'nome' => 'São Paulo']], 'cidades' => []]],
                ['chave' => 'c', 'nome' => 'Caixa de Ferramentas', 'publico_proprio' => false],
            ],
        ]))->assertCreated();

        $id = $resposta->json('id');
        $mapa = $resposta->json('conjuntos');
        $this->assertSame(['a', 'b', 'c'], array_keys($mapa));

        foreach (['a' => 3, 'b' => 2, 'c' => 2] as $chave => $quantos) {
            for ($i = 0; $i < $quantos; $i++) {
                $this->actingAs($admin)
                    ->post(self::BASE."/{$id}/videos", ['video' => UploadedFile::fake()->create("{$chave}{$i}.mp4", 500, 'video/mp4'), 'conjunto_id' => $mapa[$chave]], ['Accept' => 'application/json'])
                    ->assertCreated()
                    ->assertJsonPath('video.conjunto_id', $mapa[$chave]);
            }
        }

        $campanha = MetaAdsCampanha::with(['conjuntos', 'videos'])->findOrFail($id);
        $this->assertSame(['Árvore de Natal', 'Ferramentas', 'Caixa de Ferramentas'], $campanha->conjuntos->pluck('nome')->all());
        $this->assertSame([3, 2, 2], $campanha->conjuntos->map(fn ($c) => $campanha->videos->where('conjunto_id', $c->id)->count())->all());
        $this->assertSame([], app(MetaAdsService::class)->problemasParaEnviar($campanha));

        Queue::fake();
        $this->actingAs($admin)->post(self::BASE."/{$id}/enviar")->assertSessionHas('success');
        $this->enviarAteTerminar($campanha);

        $campanha->refresh()->load(['conjuntos', 'videos']);
        $this->assertSame(MetaAdsCampanha::STATUS_ENVIADA_PAUSADA, $campanha->status);
        $this->assertTrue($campanha->envioCompleto());

        $porCaminho = fn (string $fim) => array_values(array_filter($chamadas, fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with(parse_url($r->url(), PHP_URL_PATH), $fim)));
        $this->assertCount(1, $porCaminho('/campaigns'));
        $this->assertSame(6000, (int) $porCaminho('/campaigns')[0]['daily_budget']);

        $conjuntos = $porCaminho('/adsets');
        $this->assertSame(['Black November | Árvore de Natal', 'Black November | Ferramentas', 'Black November | Caixa de Ferramentas'], array_map(fn ($r) => $r['name'], $conjuntos));

        // Ferramentas com público próprio; os outros com o da campanha.
        $publicos = array_map(fn ($r) => json_decode($r['targeting'], true), $conjuntos);
        $this->assertSame([25, 30, 25], array_column($publicos, 'age_min'));
        $this->assertSame([1], $publicos[1]['genders']);
        $this->assertSame([['key' => '460']], $publicos[1]['geo_locations']['regions']);
        $this->assertSame(['BR'], $publicos[0]['geo_locations']['countries']);

        // 7 anúncios, cada um no conjunto certo.
        $anuncios = $porCaminho('/ads');
        $this->assertCount(7, $anuncios);
        $this->assertSame(['set1', 'set1', 'set1', 'set2', 'set2', 'set3', 'set3'], array_map(fn ($r) => $r['adset_id'], $anuncios));
        $this->assertStringStartsWith('Black November | Ferramentas | anúncio 4', $anuncios[3]['name']);

        // Ativar: 7 anúncios, 3 conjuntos, campanha, cada um uma vez.
        $chamadas = [];
        app(MetaAdsService::class)->alterarStatus($campanha, 'ACTIVE');
        $this->assertCount(11, $chamadas);
    }

    public function test_salvar_sincroniza_conjuntos_e_texto_dos_anuncios_de_uma_vez(): void
    {
        $admin = $this->admin();
        $campanha = $this->campanhaComVideos(3, 30, 2);
        [$c1, $c2] = $campanha->conjuntos->all();
        [$v1, $v2, $v3] = $campanha->videos->all();
        $this->assertSame([$c1->id, $c1->id, $c2->id], [$v1->conjunto_id, $v2->conjunto_id, $v3->conjunto_id]);

        // Renomeia o 1º, tira o 2º (com o anúncio dele), cria um novo, move o v2 para o novo e tira o v1.
        $resposta = $this->actingAs($admin)->putJson(self::BASE."/{$campanha->id}", $this->dados([
            'orcamento_tipo' => MetaAdsCampanha::ORCAMENTO_CONJUNTO,
            'orcamento_diario' => null,
            'conjuntos' => [
                ['id' => $c1->id, 'chave' => (string) $c1->id, 'nome' => 'Árvore de Natal', 'orcamento_diario' => 20],
                ['chave' => 'novo', 'nome' => 'Ferramentas', 'orcamento_diario' => 15],
            ],
            'anuncios' => [
                ['id' => $v2->id, 'conjunto' => 'novo', 'ordem' => 0, 'titulo' => 'Furadeira'],
            ],
        ]))->assertOk();

        $novo = $resposta->json('conjuntos.novo');
        $campanha->refresh()->load(['conjuntos', 'videos']);
        $this->assertSame(['Árvore de Natal', 'Ferramentas'], $campanha->conjuntos->pluck('nome')->all());
        $this->assertSame('35.00', $campanha->orcamento_diario, 'Por conjunto, o total da campanha é a soma.');
        $this->assertSame([$v2->id], $campanha->videos->pluck('id')->all());
        $this->assertSame($novo, $campanha->videos[0]->conjunto_id);
        $this->assertSame('Furadeira', $campanha->videos[0]->titulo);
        Storage::disk('local')->assertMissing($v1->path);
        Storage::disk('local')->assertMissing($v3->path);
        $this->assertNull(MetaAdsConjunto::find($c2->id));

        // Conjunto de outra campanha e orçamento abaixo do mínimo são recusados.
        $outra = $this->campanhaComVideos(0);
        $this->actingAs($admin)->putJson(self::BASE."/{$campanha->id}", $this->dados([
            'conjuntos' => [['id' => $outra->conjuntos[0]->id, 'chave' => 'x', 'nome' => 'Intruso']],
        ]))->assertStatus(422)->assertJsonValidationErrors('conjuntos.0.id');

        $this->actingAs($admin)->putJson(self::BASE."/{$campanha->id}", $this->dados([
            'orcamento_tipo' => MetaAdsCampanha::ORCAMENTO_CONJUNTO,
            'conjuntos' => [['id' => $c1->id, 'chave' => 'a', 'nome' => 'Árvore', 'orcamento_diario' => 3]],
        ]))->assertStatus(422)->assertJsonValidationErrors('conjuntos.0.orcamento_diario');
    }

    public function test_duplicar_anuncio_copia_o_arquivo_para_outro_conjunto(): void
    {
        $campanha = $this->campanhaComVideos(1, 30, 1);
        $outro = $campanha->conjuntos()->create(['nome' => 'Ferramentas', 'ordem' => 1]);
        $original = $campanha->videos[0];

        $copia = $this->actingAs($this->admin())
            ->postJson(self::BASE."/{$campanha->id}/videos/{$original->id}/duplicar", ['conjunto_id' => $outro->id, 'titulo' => 'Cópia'])
            ->assertCreated()
            ->assertJsonPath('video.conjunto_id', $outro->id)
            ->assertJsonPath('video.criativo.titulo', 'Cópia')
            ->json('video.id');

        $video = $campanha->videos()->findOrFail($copia);
        $this->assertNotSame($original->path, $video->path);
        $this->assertSame(Storage::disk('local')->get($original->path), Storage::disk('local')->get($video->path));
    }

    public function test_conjunto_sem_anuncio_e_orcamento_baixo_bloqueiam_o_envio(): void
    {
        $meta = app(MetaAdsService::class);

        $campanha = $this->campanhaComVideos(2, 30, 2);
        $campanha->conjuntos()->create(['nome' => 'Vazio', 'ordem' => 2]);
        $this->assertSame(['O conjunto Vazio não tem anúncio. Adicione um ou remova o conjunto.'], $meta->problemasParaEnviar($campanha->fresh(['conjuntos', 'videos'])));

        // Orçamento da campanha: R$ 6 por conjunto com anúncio.
        $baixo = $this->campanhaComVideos(3, 15, 3);
        $this->assertSame(['Orçamento baixo: com 3 conjuntos, o mínimo é R$ 18,00 por dia (R$ 6,00 por conjunto).'], $meta->problemasParaEnviar($baixo));

        // Vários anúncios no mesmo conjunto não multiplicam o mínimo.
        $this->assertSame([], $meta->problemasParaEnviar($this->campanhaComVideos(5, 10, 1)));
    }

    public function test_anuncio_sem_texto_proprio_nem_padrao_nao_envia(): void
    {
        $campanha = $this->campanhaComVideos(2, 30, 1);
        $campanha->update(['titulo' => null]);
        $campanha->videos[0]->update(['titulo' => 'Árvore 1']);

        $problemas = app(MetaAdsService::class)->problemasParaEnviar($campanha->fresh(['conjuntos', 'videos']));

        $this->assertSame(['Falta o título do anúncio 2 (video1) (ou um título padrão na campanha).'], $problemas);
    }

    public function test_sobe_imagem_com_produto_e_texto_proprios_e_edita_depois(): void
    {
        $admin = $this->admin();
        $produto = Product::factory()->create(['slug' => 'arvore-natal-180']);
        $campanha = $this->campanhaComVideos(0);

        $this->actingAs($admin)
            ->post(self::BASE."/{$campanha->id}/videos", [
                'video' => UploadedFile::fake()->create('arvore.jpg', 300, 'image/jpeg'),
                'produto_id' => $produto->id,
                'titulo' => 'Árvore 1,80 m',
                'chamada' => '',
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('video.tipo', 'imagem')
            ->assertJsonPath('video.link_destino', 'https://kazakora.com/produtos/arvore-natal-180')
            ->assertJsonPath('video.criativo.titulo', 'Árvore 1,80 m')
            ->assertJsonPath('video.criativo.texto', 'Parafusadeira com frete grátis');

        $anuncio = $campanha->videos()->firstOrFail();
        $this->assertNull($anuncio->chamada);
        $this->assertNull($anuncio->duracao_segundos);

        $this->actingAs($admin)
            ->putJson(self::BASE."/{$campanha->id}/videos/{$anuncio->id}", ['texto_principal' => 'Árvore cheia, 580 galhos', 'link_destino' => 'https://kazakora.devlira.com.br/x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('link_destino');

        $this->actingAs($admin)
            ->putJson(self::BASE."/{$campanha->id}/videos/{$anuncio->id}", ['produto_id' => $produto->id, 'texto_principal' => 'Árvore cheia, 580 galhos'])
            ->assertOk()
            ->assertJsonPath('video.criativo.texto', 'Árvore cheia, 580 galhos');

        // Campo que não veio volta para o padrão da campanha.
        $this->assertNull($anuncio->fresh()->titulo);
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
            ->assertInertia(fn ($page) => $page->component('Admin/MetaAds/Form', false)
                ->where('limites.max_videos', 50)
                ->where('limites.max_conjuntos', 10));
    }

    /**
     * Árvore de Natal (2026-10-18): app da Meta em modo de desenvolvimento
     * recusa o criativo. O criativo sai antes da campanha, então a recusa
     * não deixa campanha nem conjunto vazios na conta, e a mensagem diz o que fazer.
     */
    public function test_app_em_desenvolvimento_para_no_criativo_sem_criar_campanha(): void
    {
        $chamadas = [];
        Http::fake(function (HttpRequest $request) use (&$chamadas) {
            $caminho = Str::after(parse_url($request->url(), PHP_URL_PATH), '/v23.0/');
            $chamadas[] = $caminho;

            return match (true) {
                $caminho === 'act_123/advideos' => Http::response(['id' => 'vid1']),
                str_ends_with($caminho, '/thumbnails') => Http::response(['data' => [['uri' => 'https://scontent.example/c.jpg']]]),
                $caminho === 'vid1' => Http::response(['status' => ['video_status' => 'ready']]),
                $caminho === 'act_123/adcreatives' => Http::response(['error' => [
                    'message' => 'Invalid parameter', 'code' => 100, 'error_subcode' => 1885183,
                    'error_user_title' => 'App em desenvolvimento', 'error_user_msg' => 'Ele deve estar em modo público.',
                ]], 400),
                default => Http::response(['id' => 'x'.Str::random(4)]),
            };
        });

        $campanha = $this->campanhaComVideos(1, 30);
        $campanha->forceFill(['status' => MetaAdsCampanha::STATUS_ENVIANDO])->save();
        $job = new EnviarCampanhaMetaAds($campanha->id);
        for ($i = 0; $i < 5 && $campanha->fresh()->status === MetaAdsCampanha::STATUS_ENVIANDO; $i++) {
            $job->handle(app(MetaAdsService::class));
        }

        $campanha->refresh();
        $this->assertSame(MetaAdsCampanha::STATUS_ERRO, $campanha->status);
        $this->assertNull($campanha->meta_campaign_id);
        $this->assertStringContainsString('modo de desenvolvimento', $campanha->ultimo_erro);
        $this->assertStringContainsString('Ao vivo', $campanha->ultimo_erro);
        $this->assertNotContains('act_123/campaigns', $chamadas);
        $this->assertNotContains('act_123/adsets', $chamadas);
    }

    /**
     * Reenvio de campanha que o Lira completou à mão no Gerenciador: o
     * anúncio com o mesmo vídeo já está no conjunto, então é aproveitado.
     */
    public function test_reenvio_aproveita_anuncio_feito_no_gerenciador_com_o_mesmo_video(): void
    {
        $posts = [];
        Http::fake(function (HttpRequest $request) use (&$posts) {
            $caminho = Str::after(parse_url($request->url(), PHP_URL_PATH), '/v23.0/');
            if ($request->method() === 'POST') {
                $posts[] = $caminho;
            }

            return match (true) {
                $caminho === 'set1/ads' => Http::response(['data' => [
                    ['id' => 'ad-outro', 'effective_status' => 'ACTIVE', 'creative' => ['id' => 'cri-outro', 'object_story_spec' => ['video_data' => ['video_id' => 'vid-outro']]]],
                    ['id' => 'ad-manual', 'effective_status' => 'ACTIVE', 'creative' => ['id' => 'cri-manual', 'object_story_spec' => ['video_data' => ['video_id' => 'vid1']]]],
                ]]),
                default => Http::response(['error' => ['message' => 'não devia chamar '.$caminho]], 400),
            };
        });

        $campanha = $this->campanhaComVideos(1, 30);
        $campanha->forceFill(['meta_campaign_id' => 'camp1', 'status' => MetaAdsCampanha::STATUS_ENVIANDO])->save();
        $campanha->conjuntos()->update(['meta_adset_id' => 'set1']);
        $campanha->videos()->update(['meta_video_id' => 'vid1', 'meta_thumbnail_url' => 'https://scontent.example/c.jpg', 'meta_adset_id' => 'set1']);

        $this->enviarAteTerminar($campanha);

        $video = $campanha->videos()->first();
        $this->assertSame('ad-manual', $video->meta_ad_id);
        $this->assertSame('cri-manual', $video->meta_creative_id);
        $this->assertSame([], $posts, 'Não pode criar criativo nem anúncio repetido.');
        $this->assertSame(MetaAdsCampanha::STATUS_ENVIADA_PAUSADA, $campanha->fresh()->status);
    }

    /** Como a campanha manual da Árvore: vários textos, UTM, atribuição e melhorias ditas. */
    public function test_criativo_com_varios_textos_utm_e_conjunto_com_atribuicao(): void
    {
        $chamadas = [];
        $this->fakeMeta($chamadas);
        $admin = $this->admin();

        $id = $this->actingAs($admin)->postJson(self::BASE, $this->dados([
            'objetivo' => 'OUTCOME_TRAFFIC',
            'texto_principal' => 'Texto 1',
            'titulo' => 'Título 1',
            'descricao' => 'Descrição 1',
            'textos_alternativos' => [
                'textos' => ['Texto 2', '', 'Texto 2', 'Texto 3'],
                'titulos' => ['Título 2'],
                'descricoes' => [],
            ],
        ]))->assertCreated()->json('id');

        $campanha = MetaAdsCampanha::findOrFail($id);
        $this->assertSame(['textos' => ['Texto 2', 'Texto 3'], 'titulos' => ['Título 2'], 'descricoes' => []], $campanha->textos_alternativos);

        $path = "meta-ads/campanha-{$id}/v0.mp4";
        Storage::disk('local')->put($path, 'video');
        $campanha->videos()->create(['conjunto_id' => $campanha->conjuntos()->value('id'), 'ordem' => 0, 'nome_original' => 'arvore.mp4', 'path' => $path, 'tamanho' => 10]);

        $this->enviarAteTerminar($campanha);

        $porCaminho = fn (string $fim) => array_values(array_filter($chamadas, fn (HttpRequest $r) => $r->method() === 'POST' && str_ends_with(parse_url($r->url(), PHP_URL_PATH), $fim)));
        $criativo = $porCaminho('/adcreatives')[0];

        $this->assertSame(MetaAdsService::URL_TAGS, $criativo['url_tags']);
        $feed = json_decode($criativo['asset_feed_spec'], true);
        $this->assertSame([['text' => 'Texto 1'], ['text' => 'Texto 2'], ['text' => 'Texto 3']], $feed['bodies']);
        $this->assertSame([['text' => 'Título 1'], ['text' => 'Título 2']], $feed['titles']);
        $this->assertSame([['text' => 'Descrição 1']], $feed['descriptions']);
        $this->assertSame('DEGREES_OF_FREEDOM', $feed['optimization_type']);
        $video = json_decode($criativo['object_story_spec'], true)['video_data'];
        $this->assertArrayNotHasKey('message', $video);
        $this->assertSame('https://kazakora.com/produtos/parafusadeira', $video['call_to_action']['value']['link']);
        $melhorias = json_decode($criativo['degrees_of_freedom_spec'], true)['creative_features_spec'];
        $this->assertSame('OPT_IN', $melhorias['enhance_cta']['enroll_status']);

        $conjunto = $porCaminho('/adsets')[0];
        $this->assertSame(MetaAdsService::ATRIBUICAO, json_decode($conjunto['attribution_spec'], true));
        $this->assertSame(['home', 'recent', 'frequently_in'], json_decode($conjunto['targeting'], true)['geo_locations']['location_types']);

        // A campanha só nasce depois do criativo.
        $ordem = array_map(fn (HttpRequest $r) => Str::after(parse_url($r->url(), PHP_URL_PATH), '/v23.0/'), array_filter($chamadas, fn ($r) => $r->method() === 'POST'));
        $ordem = array_values($ordem);
        $this->assertLessThan(array_search('act_123/campaigns', $ordem), array_search('act_123/adcreatives', $ordem));
    }

    public function test_sem_textos_extras_o_texto_vai_no_proprio_criativo(): void
    {
        $chamadas = [];
        $this->fakeMeta($chamadas);
        $campanha = $this->campanhaComVideos(1, 30);

        $this->enviarAteTerminar($campanha);

        $criativo = array_values(array_filter($chamadas, fn (HttpRequest $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/adcreatives')))[0];
        $this->assertArrayNotHasKey('asset_feed_spec', $criativo->data());
        $this->assertSame('Parafusadeira com frete grátis', json_decode($criativo['object_story_spec'], true)['video_data']['message']);
    }

    public function test_assumida_no_gerenciador_nao_envia_ativa_nem_pausa(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $campanha = $this->campanhaComVideos(1, 30);
        $campanha->forceFill(['status' => MetaAdsCampanha::STATUS_ERRO, 'meta_campaign_id' => 'camp1', 'ultimo_erro' => 'Falhou'])->save();

        $rascunho = $this->campanhaComVideos(1, 30);
        $this->actingAs($admin)->post(self::BASE."/{$rascunho->id}/assumir")->assertSessionHas('error');
        $this->assertSame(MetaAdsCampanha::STATUS_RASCUNHO, $rascunho->fresh()->status);

        $this->actingAs($admin)->post(self::BASE."/{$campanha->id}/assumir")->assertSessionHas('success');
        $campanha->refresh();
        $this->assertSame(MetaAdsCampanha::STATUS_GERENCIADOR, $campanha->status);
        $this->assertNull($campanha->ultimo_erro);

        $this->actingAs($admin)->post(self::BASE."/{$campanha->id}/enviar")->assertSessionHas('error');
        $this->actingAs($admin)->post(self::BASE."/{$campanha->id}/pausar")->assertSessionHas('error');
        Queue::assertNothingPushed();

        $this->actingAs($admin)->get(self::BASE."/{$campanha->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('campanha.status_rotulo', 'Assumida no Gerenciador')
                ->where('pode.enviar', false)
                ->where('pode.assumir', false));
    }
}
