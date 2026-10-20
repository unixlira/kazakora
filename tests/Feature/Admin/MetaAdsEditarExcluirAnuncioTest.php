<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Marketplace\Jobs\TrocarCriativoMetaAds;
use App\Modules\Marketplace\Models\MetaAdsCampanha;
use App\Modules\Marketplace\Models\MetaAdsVideo;
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
 * Meta ADS (2026-10-19): editar e excluir anúncio pela tela, inclusive o
 * que já está na Meta. Tudo contra a Graph API falsa (Http::fake): nenhum
 * anúncio real é tocado.
 */
class MetaAdsEditarExcluirAnuncioTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/admin/publicidade/meta-ads/campanhas';

    /** @var list<HttpRequest> */
    private array $chamadas = [];

    /** Respostas especiais por caminho (ex.: erro no criativo). */
    private array $respostas = [];

    /** Anúncios ativos que a Meta devolve em set1/ads. */
    private array $ativosNoConjunto = ['ad1', 'ad2'];

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
            'inertia.testing.ensure_pages_exist' => false,
        ]);

        $contadores = ['vid' => 0, 'set' => 0, 'hash' => 0, 'cri' => 0, 'ad' => 0];
        Http::fake(function (HttpRequest $request) use (&$contadores) {
            $this->chamadas[] = $request;
            $caminho = $this->caminho($request);

            if (isset($this->respostas[$caminho])) {
                return $this->respostas[$caminho];
            }

            return match (true) {
                $caminho === 'act_123/campaigns' => Http::response(['id' => 'camp1']),
                $caminho === 'act_123/advideos' => Http::response(['id' => 'vid'.(++$contadores['vid'])]),
                $caminho === 'act_123/adimages' => Http::response(['images' => ['x.jpg' => ['hash' => 'hash'.(++$contadores['hash']), 'url' => 'https://scontent.example/img.jpg']]]),
                str_ends_with($caminho, '/thumbnails') => Http::response(['data' => [['uri' => "https://scontent.example/{$caminho}.jpg", 'is_preferred' => true]]]),
                str_starts_with($caminho, 'vid') => Http::response(['status' => ['video_status' => 'ready']]),
                $caminho === 'act_123/adsets' => Http::response(['id' => 'set'.(++$contadores['set'])]),
                $caminho === 'act_123/adcreatives' => Http::response(['id' => 'cri'.(++$contadores['cri'])]),
                $caminho === 'act_123/ads' => Http::response(['id' => 'ad'.(++$contadores['ad'])]),
                $caminho === 'set1/ads' && $request->method() === 'GET' => Http::response(['data' => array_map(
                    fn ($id) => ['id' => $id, 'effective_status' => 'ACTIVE'],
                    $this->ativosNoConjunto,
                )]),
                default => Http::response(['success' => true]),
            };
        });
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function caminho(HttpRequest $r): string
    {
        return Str::after(parse_url($r->url(), PHP_URL_PATH), '/v23.0/');
    }

    /** @return list<array{0: string, 1: array}> POSTs feitos (caminho e corpo) */
    private function posts(): array
    {
        return array_values(array_map(
            fn (HttpRequest $r) => [$this->caminho($r), $r->data()],
            array_filter($this->chamadas, fn (HttpRequest $r) => $r->method() === 'POST'),
        ));
    }

    private function campanha(int $anuncios = 2): MetaAdsCampanha
    {
        $campanha = MetaAdsCampanha::create([
            'nome' => 'Árvores Natal',
            'objetivo' => 'OUTCOME_TRAFFIC',
            'orcamento_diario' => 30,
            'inicio' => now()->addDay()->toDateString(),
            'idade_min' => 25,
            'idade_max' => 55,
            'genero' => 'todos',
            'localizacoes' => ['regioes' => [], 'cidades' => []],
            'link_destino' => 'https://kazakora.com/produtos/arvore',
            'texto_principal' => 'Árvore cheia',
            'titulo' => 'Árvore 1,80 m',
            'chamada' => 'SHOP_NOW',
            'status' => MetaAdsCampanha::STATUS_RASCUNHO,
        ]);
        $conjunto = $campanha->conjuntos()->create(['nome' => 'Natal', 'ordem' => 0]);

        for ($i = 0; $i < $anuncios; $i++) {
            $path = "meta-ads/campanha-{$campanha->id}/v{$i}.mp4";
            Storage::disk('local')->put($path, 'video-'.$i);
            $campanha->videos()->create([
                'conjunto_id' => $conjunto->id, 'ordem' => $i, 'nome_original' => "video{$i}.mp4", 'path' => $path, 'tamanho' => 1000,
            ]);
        }

        return $campanha->load(['conjuntos', 'videos']);
    }

    /** Campanha enviada (pausada) com anúncios ad1, ad2 no conjunto set1. */
    private function campanhaNaMeta(int $anuncios = 2): MetaAdsCampanha
    {
        $campanha = $this->campanha($anuncios);
        $meta = app(MetaAdsService::class);
        for ($i = 0; $i < 20 && $meta->avancarEnvio($campanha->fresh(['conjuntos', 'videos'])) !== MetaAdsService::ENVIO_PRONTO; $i++);
        $this->chamadas = [];

        $campanha = $campanha->fresh(['conjuntos', 'videos']);
        $this->assertSame('ad1', $campanha->videos[0]->meta_ad_id);

        return $campanha;
    }

    private function url(MetaAdsCampanha $campanha, MetaAdsVideo $video, string $extra = ''): string
    {
        return self::BASE."/{$campanha->id}/videos/{$video->id}{$extra}";
    }

    /** @return array<string, mixed> todos os campos do modal, como a tela manda */
    private function campos(array $extra = []): array
    {
        return $extra + [
            'nome' => null,
            'produto_id' => null,
            'link_destino' => 'https://kazakora.com/produtos/arvore',
            'texto_principal' => 'Árvore cheia',
            'titulo' => 'Árvore 1,80 m',
            'descricao' => null,
            'chamada' => 'SHOP_NOW',
        ];
    }

    public function test_so_no_kazakora_salva_direto_sem_chamar_a_meta(): void
    {
        $campanha = $this->campanha(1);
        $video = $campanha->videos[0];

        $this->actingAs($this->admin())
            ->putJson($this->url($campanha, $video), $this->campos(['nome' => 'Árvore branca', 'texto_principal' => 'Texto novo']))
            ->assertOk()
            ->assertJsonPath('message', 'Anúncio atualizado.')
            ->assertJsonPath('video.editar.nome', 'Árvore branca')
            ->assertJsonPath('video.editar.na_meta', false);

        $this->assertSame([], $this->chamadas);
        $this->assertSame('Texto novo', $video->fresh()->texto_principal);
        $this->assertSame('Árvore branca', $video->fresh()->nome);
    }

    public function test_so_no_kazakora_troca_o_arquivo_e_apaga_o_antigo(): void
    {
        $campanha = $this->campanha(1);
        $video = $campanha->videos[0];
        $video->update(['meta_video_id' => 'vid-velho', 'meta_thumbnail_url' => 'https://x/c.jpg']);
        $antigo = $video->path;

        $this->actingAs($this->admin())
            ->post($this->url($campanha, $video), $this->campos([
                '_method' => 'PUT',
                'arquivo' => UploadedFile::fake()->image('nova.jpg', 1080, 1350),
            ]), ['Accept' => 'application/json'])
            ->assertOk();

        $video->refresh();
        $this->assertSame(MetaAdsVideo::TIPO_IMAGEM, $video->tipo);
        $this->assertSame('nova.jpg', $video->nome_original);
        $this->assertNull($video->meta_video_id);
        $this->assertNull($video->meta_thumbnail_url);
        Storage::disk('local')->assertMissing($antigo);
        Storage::disk('local')->assertExists($video->path);
        $this->assertSame([], $this->chamadas);
    }

    public function test_na_meta_so_o_nome_muda_o_nome_do_anuncio(): void
    {
        $campanha = $this->campanhaNaMeta();
        $video = $campanha->videos[0];
        $criativo = $video->meta_creative_id;

        $this->actingAs($this->admin())
            ->putJson($this->url($campanha, $video), $this->campos(['nome' => 'Árvore 1 | vídeo curto']))
            ->assertOk()
            ->assertJsonPath('message', 'Anúncio atualizado.');

        $this->assertSame([['ad1', ['name' => 'Árvore 1 | vídeo curto']]], $this->posts());
        $video->refresh();
        $this->assertSame('Árvore 1 | vídeo curto', $video->nome);
        $this->assertSame($criativo, $video->meta_creative_id);
        $this->assertNull($video->edicao_pendente);
    }

    public function test_na_meta_sem_mudanca_nao_chama_a_meta(): void
    {
        $campanha = $this->campanhaNaMeta();
        $video = $campanha->videos[0];
        $nome = app(MetaAdsService::class)->nomeDoAnuncio($campanha, $video);
        $this->assertSame('Árvores Natal | Natal | anúncio 1 (video0)', $nome);

        $this->actingAs($this->admin())
            ->putJson($this->url($campanha, $video), $this->campos(['nome' => $nome]))
            ->assertOk();

        $this->assertSame([], $this->posts());
    }

    public function test_na_meta_texto_novo_cria_criativo_e_exige_confirmar_a_analise(): void
    {
        $campanha = $this->campanhaNaMeta();
        $video = $campanha->videos[0];
        $admin = $this->admin();

        $this->actingAs($admin)
            ->putJson($this->url($campanha, $video), $this->campos(['texto_principal' => 'Árvore cheia, 580 galhos']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirmo_analise');
        $this->assertSame([], $this->posts());

        $this->actingAs($admin)
            ->putJson($this->url($campanha, $video), $this->campos(['texto_principal' => 'Árvore cheia, 580 galhos', 'chamada' => 'LEARN_MORE', 'confirmo_analise' => true]))
            ->assertOk()
            ->assertJsonPath('message', 'Anúncio atualizado. A Meta está analisando o criativo novo.');

        $posts = $this->posts();
        $this->assertSame(['act_123/adcreatives', 'ad1'], array_column($posts, 0));
        $spec = json_decode($posts[0][1]['object_story_spec'], true);
        $this->assertSame('Árvore cheia, 580 galhos', $spec['video_data']['message']);
        $this->assertSame('LEARN_MORE', $spec['video_data']['call_to_action']['type']);
        $this->assertSame('vid1', $spec['video_data']['video_id']);
        $this->assertSame(['creative' => json_encode(['creative_id' => 'cri3'])], $posts[1][1]);

        $video->refresh();
        $this->assertSame('cri3', $video->meta_creative_id);
        $this->assertSame('Árvore cheia, 580 galhos', $video->texto_principal);
        $this->assertSame('LEARN_MORE', $video->chamada);
    }

    public function test_na_meta_link_fora_da_loja_e_recusado(): void
    {
        $campanha = $this->campanhaNaMeta();

        $this->actingAs($this->admin())
            ->putJson($this->url($campanha, $campanha->videos[0]), $this->campos(['link_destino' => 'https://kazakora.com.br/x', 'confirmo_analise' => true]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('link_destino');

        $this->assertSame([], $this->posts());
    }

    public function test_erro_da_meta_nao_altera_nada_no_kazakora(): void
    {
        $campanha = $this->campanhaNaMeta();
        $video = $campanha->videos[0];
        $antes = $video->only(['texto_principal', 'nome', 'meta_creative_id', 'path', 'tipo']);
        $this->respostas['ad1'] = Http::response(['error' => ['message' => 'Anúncio em revisão', 'code' => 100]], 400);

        $this->actingAs($this->admin())
            ->post($this->url($campanha, $video), $this->campos([
                '_method' => 'PUT',
                'nome' => 'Outro nome',
                'texto_principal' => 'Texto que a Meta recusa',
                'confirmo_analise' => '1',
                'arquivo' => UploadedFile::fake()->image('nova.jpg', 1080, 1350),
            ]), ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Não deu para salvar na Meta: Anúncio em revisão (código 100). Nada foi alterado.');

        $video->refresh();
        $this->assertSame($antes, $video->only(['texto_principal', 'nome', 'meta_creative_id', 'path', 'tipo']));
        $this->assertNull($video->edicao_pendente);
        $this->assertNull($video->ultimo_erro);
        // Só os arquivos antigos continuam no disco (o novo foi apagado).
        $this->assertSame(
            $campanha->videos->pluck('path')->sort()->values()->all(),
            collect(Storage::disk('local')->allFiles("meta-ads/campanha-{$campanha->id}"))->sort()->values()->all(),
        );
    }

    public function test_na_meta_imagem_nova_sobe_e_vira_criativo_na_hora(): void
    {
        $campanha = $this->campanhaNaMeta();
        $video = $campanha->videos[0];
        $antigo = $video->path;

        $this->actingAs($this->admin())
            ->post($this->url($campanha, $video), $this->campos([
                '_method' => 'PUT',
                'confirmo_analise' => '1',
                'arquivo' => UploadedFile::fake()->image('arvore.jpg', 1080, 1350),
            ]), ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('message', 'Anúncio atualizado. A Meta está analisando o criativo novo.');

        $this->assertSame(['act_123/adimages', 'act_123/adcreatives', 'ad1'], array_column($this->posts(), 0));
        $spec = json_decode($this->posts()[1][1]['object_story_spec'], true);
        $this->assertSame('hash1', $spec['link_data']['image_hash']);

        $video->refresh();
        $this->assertSame(MetaAdsVideo::TIPO_IMAGEM, $video->tipo);
        $this->assertSame('hash1', $video->meta_image_hash);
        $this->assertNull($video->meta_video_id);
        Storage::disk('local')->assertMissing($antigo);
    }

    public function test_na_meta_video_novo_espera_a_meta_processar_em_segundo_plano(): void
    {
        $campanha = $this->campanhaNaMeta();
        $video = $campanha->videos[0];
        $antigo = $video->path;
        Queue::fake();

        $this->actingAs($this->admin())
            ->post($this->url($campanha, $video), $this->campos([
                '_method' => 'PUT',
                'confirmo_analise' => '1',
                'arquivo' => UploadedFile::fake()->create('novo.mp4', 200, 'video/mp4'),
            ]), ['Accept' => 'application/json'])
            ->assertStatus(202)
            ->assertJsonPath('video.editar.trocando_arquivo', true);

        Queue::assertPushed(TrocarCriativoMetaAds::class, fn ($job) => $job->videoId === $video->id);
        // Até a Meta aceitar, o anúncio segue com o arquivo e o criativo antigos.
        $this->assertSame($antigo, $video->fresh()->path);
        $this->assertSame([], $this->posts());

        // Enquanto processa, não dá para editar nem excluir de novo.
        $this->actingAs($this->admin())->deleteJson($this->url($campanha, $video), ['confirmo_exclusao' => true])->assertStatus(409);

        $meta = app(MetaAdsService::class);
        for ($i = 0; $i < 10 && $meta->avancarEdicao($campanha->fresh('conjuntos'), $video->fresh(), true) !== MetaAdsService::ENVIO_PRONTO; $i++);

        $this->assertSame(['act_123/advideos', 'act_123/adcreatives', 'ad1'], array_column($this->posts(), 0));
        $video->refresh();
        $this->assertSame('vid3', $video->meta_video_id);
        $this->assertSame('novo.mp4', $video->nome_original);
        $this->assertSame('cri3', $video->meta_creative_id);
        $this->assertNull($video->edicao_pendente);
        Storage::disk('local')->assertMissing($antigo);
    }

    public function test_excluir_so_no_kazakora_apaga_de_vez_com_o_arquivo(): void
    {
        $campanha = $this->campanha(2);
        $video = $campanha->videos[0];

        $this->actingAs($this->admin())->deleteJson($this->url($campanha, $video))
            ->assertOk()->assertJsonPath('message', 'Anúncio excluído do KazaKora.');

        $this->assertNull(MetaAdsVideo::withTrashed()->find($video->id));
        Storage::disk('local')->assertMissing($video->path);
        $this->assertSame([], $this->chamadas);
    }

    public function test_excluir_na_meta_marca_deleted_e_guarda_o_historico(): void
    {
        $campanha = $this->campanhaNaMeta();
        $video = $campanha->videos[0];
        $admin = $this->admin();

        $this->actingAs($admin)->deleteJson($this->url($campanha, $video))->assertStatus(422)->assertJsonValidationErrors('confirmo_exclusao');
        $this->assertSame([], $this->posts());

        $this->actingAs($admin)->deleteJson($this->url($campanha, $video), ['confirmo_exclusao' => true])
            ->assertOk()->assertJsonPath('message', 'Anúncio excluído na Meta.');

        $this->assertSame([['ad1', ['status' => 'DELETED']]], $this->posts());
        $this->assertNull(MetaAdsVideo::find($video->id));
        $excluido = MetaAdsVideo::withTrashed()->find($video->id);
        $this->assertNotNull($excluido->deleted_at);
        $this->assertSame('ad1', $excluido->meta_ad_id);

        // Sai da tela da campanha e não volta no reenvio.
        $this->actingAs($admin)->get(self::BASE."/{$campanha->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->has('videos', 1)->where('videos.0.meta_ad_id', 'ad2'));
    }

    public function test_ultimo_anuncio_ativo_do_conjunto_pede_confirmacao_extra(): void
    {
        $campanha = $this->campanhaNaMeta();
        $video = $campanha->videos[0];
        $this->ativosNoConjunto = ['ad1'];
        $admin = $this->admin();

        $this->actingAs($admin)->deleteJson($this->url($campanha, $video), ['confirmo_exclusao' => true])
            ->assertStatus(422)
            ->assertJsonPath('ultimo_ativo', true)
            ->assertJsonPath('message', 'É o último anúncio ativo do conjunto "Natal". Sem ele o conjunto para de entregar.');
        $this->assertSame([], $this->posts());
        $this->assertNotNull($video->fresh());

        $this->actingAs($admin)->deleteJson($this->url($campanha, $video), ['confirmo_exclusao' => true, 'sem_anuncio_ativo' => true])->assertOk();
        $this->assertSame([['ad1', ['status' => 'DELETED']]], $this->posts());
    }

    public function test_meta_recusa_a_exclusao_e_o_anuncio_continua(): void
    {
        $campanha = $this->campanhaNaMeta();
        $video = $campanha->videos[0];
        $this->respostas['ad1'] = Http::response(['error' => ['message' => 'Sem permissão', 'code' => 200]], 403);

        $this->actingAs($this->admin())->deleteJson($this->url($campanha, $video), ['confirmo_exclusao' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', 'A Meta recusou a exclusão: Sem permissão (código 200). O anúncio continua como estava.');

        $this->assertNotNull($video->fresh());
        Storage::disk('local')->assertExists($video->path);
    }

    public function test_pausar_em_vez_de_excluir(): void
    {
        $campanha = $this->campanhaNaMeta();
        $video = $campanha->videos[0];
        $video->update(['ativado_em' => now()]);

        $this->actingAs($this->admin())->postJson($this->url($campanha, $video, '/pausar'))
            ->assertOk()->assertJsonPath('message', 'Anúncio pausado na Meta.');

        $this->assertSame([['ad1', ['status' => 'PAUSED']]], $this->posts());
        $this->assertNull($video->fresh()->ativado_em);
        $this->assertNotNull($video->fresh());
    }

    public function test_conjunto_enviando_bloqueia_editar_e_excluir(): void
    {
        $campanha = $this->campanhaNaMeta();
        $campanha->conjuntos[0]->update(['enviando' => true]);
        $video = $campanha->videos[0];

        $this->actingAs($this->admin())->putJson($this->url($campanha, $video), $this->campos(['nome' => 'x']))->assertStatus(409);
        $this->actingAs($this->admin())->deleteJson($this->url($campanha, $video), ['confirmo_exclusao' => true])->assertStatus(409);
        $this->assertSame([], $this->posts());
    }

    public function test_sem_permissao_nao_edita_nem_exclui(): void
    {
        $campanha = $this->campanhaNaMeta();
        $video = $campanha->videos[0];
        $vendedor = User::factory()->create(['role' => 'customer']);

        $this->actingAs($vendedor)->putJson($this->url($campanha, $video), $this->campos(['nome' => 'x']))->assertForbidden();
        $this->actingAs($vendedor)->deleteJson($this->url($campanha, $video), ['confirmo_exclusao' => true])->assertForbidden();
        $this->assertSame([], $this->posts());
    }

    public function test_espelho_manda_local_id_e_ultimo_ativo_por_anuncio(): void
    {
        $campanha = $this->campanhaNaMeta();
        $campanha->update(['status' => MetaAdsCampanha::STATUS_ATIVA]);

        // A Graph falsa do setUp responde primeiro: troca só estas leituras.
        $this->respostas = [
            'act_123/campaigns' => Http::response(['data' => [
                ['id' => 'camp1', 'name' => 'Árvores Natal', 'effective_status' => 'ACTIVE', 'objective' => 'OUTCOME_TRAFFIC', 'daily_budget' => '3000'],
            ]]),
            'act_123/adsets' => Http::response(['data' => [
                ['id' => 'set1', 'name' => 'Natal', 'campaign_id' => 'camp1', 'effective_status' => 'ACTIVE'],
            ]]),
            'act_123/ads' => Http::response(['data' => [
                ['id' => 'ad1', 'name' => 'Árvore 1', 'adset_id' => 'set1', 'campaign_id' => 'camp1', 'effective_status' => 'ACTIVE'],
                ['id' => 'ad2', 'name' => 'Árvore 2', 'adset_id' => 'set1', 'campaign_id' => 'camp1', 'effective_status' => 'PAUSED'],
                ['id' => 'ad9', 'name' => 'Feito no Gerenciador', 'adset_id' => 'set1', 'campaign_id' => 'camp1', 'effective_status' => 'PAUSED'],
            ]]),
            'act_123/insights' => Http::response(['data' => []]),
        ];

        $this->actingAs($this->admin())->get(self::BASE)->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('produtos')
                ->has('chamadas')
                ->loadDeferredProps(fn ($reload) => $reload
                    ->where('espelho.erro', null)
                    ->where('espelho.campanhas.0.conjuntos.0.anuncios.1.id', 'ad1')
                    ->where('espelho.campanhas.0.conjuntos.0.anuncios.1.local.id', $campanha->videos[0]->id)
                    ->where('espelho.campanhas.0.conjuntos.0.anuncios.1.local.campanha_id', $campanha->id)
                    ->where('espelho.campanhas.0.conjuntos.0.anuncios.1.local.conjunto', 'Natal')
                    ->where('espelho.campanhas.0.conjuntos.0.anuncios.1.local.texto_principal', 'Árvore cheia')
                    ->where('espelho.campanhas.0.conjuntos.0.anuncios.1.local.bloqueio', null)
                    ->where('espelho.campanhas.0.conjuntos.0.anuncios.1.ultimo_ativo', true)
                    ->where('espelho.campanhas.0.conjuntos.0.anuncios.2.ultimo_ativo', false)
                    // Criado direto no Gerenciador (vem primeiro na ordem por nome): sem local.
                    ->where('espelho.campanhas.0.conjuntos.0.anuncios.0.id', 'ad9')
                    ->where('espelho.campanhas.0.conjuntos.0.anuncios.0.local', null)));
    }
}
