<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Marketplace\Models\MetaAdsCampanha;
use App\Services\Meta\MetaAdsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Meta ADS (2026-10-21): trocar o link do anúncio direto na tabela e status
 * ao vivo da Meta. Tudo contra a Graph API falsa (Http::fake).
 */
class MetaAdsLinkEStatusTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/admin/publicidade/meta-ads/gerenciar';

    /** @var list<HttpRequest> */
    private array $chamadas = [];

    private array $objetos = [];

    private array $status = [
        'act_123/campaigns' => [['id' => '1001', 'effective_status' => 'PAUSED']],
        'act_123/adsets' => [['id' => '2001', 'effective_status' => 'ACTIVE']],
        'act_123/ads' => [['id' => '3009', 'effective_status' => 'PENDING_REVIEW']],
    ];

    private ?array $erroNoCriativo = null;

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

        $criativo = [
            'id' => '5009', 'name' => 'Criativo do Gerenciador', 'url_tags' => 'utm_source=meta',
            'object_story_spec' => ['page_id' => '999', 'video_data' => [
                'video_id' => '4009', 'image_hash' => 'abc', 'image_url' => 'https://img/x.jpg',
                'call_to_action' => ['type' => 'SHOP_NOW', 'value' => ['link' => 'https://kazakora.com/produtos/velho']],
            ]],
        ];
        $this->objetos = [
            '3009' => ['id' => '3009', 'name' => 'Feito no Gerenciador', 'account_id' => '123', 'campaign_id' => '1001', 'adset_id' => '2001', 'effective_status' => 'ACTIVE', 'creative' => $criativo],
            '3001' => ['id' => '3001', 'name' => 'Do KazaKora', 'account_id' => '123', 'campaign_id' => '1001', 'adset_id' => '2001', 'effective_status' => 'ACTIVE'],
            '3005' => ['id' => '3005', 'name' => 'Catálogo', 'account_id' => '123', 'campaign_id' => '1001', 'adset_id' => '2001', 'effective_status' => 'ACTIVE',
                'creative' => ['id' => '5005', 'product_set_id' => '88', 'object_story_spec' => ['page_id' => '999']]],
        ];

        Http::fake(function (HttpRequest $request) {
            $this->chamadas[] = $request;
            $caminho = Str::after(parse_url($request->url(), PHP_URL_PATH), '/v23.0/');

            if ($request->method() === 'POST') {
                if ($caminho === 'act_123/adcreatives') {
                    return $this->erroNoCriativo ? Http::response($this->erroNoCriativo, 400) : Http::response(['id' => '7777']);
                }

                return Http::response(['success' => true]);
            }

            if (isset($this->status[$caminho])) {
                return Http::response(['data' => $this->status[$caminho]]);
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

    private function campanhaLocal(string $status = MetaAdsCampanha::STATUS_ATIVA): MetaAdsCampanha
    {
        $campanha = MetaAdsCampanha::create([
            'nome' => 'Black November', 'objetivo' => 'OUTCOME_SALES', 'orcamento_diario' => 30,
            'inicio' => now()->toDateString(), 'idade_min' => 25, 'idade_max' => 55, 'genero' => 'todos',
            'link_destino' => 'https://kazakora.com/produtos/arvore', 'chamada' => 'SHOP_NOW',
            'status' => $status, 'meta_campaign_id' => '1001',
        ]);
        $conjunto = $campanha->conjuntos()->create(['nome' => 'Natal', 'ordem' => 0, 'meta_adset_id' => '2001']);
        $campanha->videos()->create([
            'conjunto_id' => $conjunto->id, 'ordem' => 0, 'nome_original' => 'v0.mp4', 'path' => "meta-ads/campanha-{$campanha->id}/v0.mp4",
            'tamanho' => 1000, 'meta_adset_id' => '2001', 'meta_ad_id' => '3001', 'meta_creative_id' => '5001', 'meta_video_id' => '4001',
            'meta_thumbnail_url' => 'https://img/capa.jpg',
        ]);

        return $campanha->fresh(['conjuntos', 'videos']);
    }

    public function test_link_exige_confirmar_a_analise(): void
    {
        $this->actingAs($this->admin())
            ->putJson(self::BASE.'/anuncio/3009/link', ['link' => 'https://kazakora.com/produtos/novo'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('confirmo_analise');

        $this->assertSame([], $this->posts());
    }

    public function test_link_de_homolog_ou_com_br_e_recusado(): void
    {
        foreach (['https://kazakora.com.br/produtos/x', 'https://kazakora.devlira.com.br/produtos/x', 'http://kazakora.com/produtos/x'] as $link) {
            $this->actingAs($this->admin())
                ->putJson(self::BASE.'/anuncio/3009/link', ['link' => $link, 'confirmo_analise' => true])
                ->assertStatus(422)
                ->assertJsonValidationErrors('link');
        }

        $this->assertSame([], $this->posts());
    }

    public function test_anuncio_do_gerenciador_ganha_criativo_novo_com_o_link(): void
    {
        $this->actingAs($this->admin())
            ->putJson(self::BASE.'/anuncio/3009/link', ['link' => 'https://kazakora.com/produtos/novo', 'confirmo_analise' => true])
            ->assertOk();

        [$criativo, $anuncio] = $this->posts();
        $this->assertSame('act_123/adcreatives', $criativo[0]);
        $spec = json_decode($criativo[1]['object_story_spec'], true);
        $this->assertSame('https://kazakora.com/produtos/novo', $spec['video_data']['call_to_action']['value']['link']);
        $this->assertSame('4009', $spec['video_data']['video_id']);
        $this->assertArrayNotHasKey('image_url', $spec['video_data']);
        $this->assertSame('utm_source=meta', $criativo[1]['url_tags']);
        $this->assertSame(['3009', ['creative' => json_encode(['creative_id' => '7777'])]], $anuncio);
    }

    public function test_meta_recusa_o_criativo_e_o_anuncio_fica_como_estava(): void
    {
        $this->erroNoCriativo = ['error' => ['message' => 'Link inválido']];

        $this->actingAs($this->admin())
            ->putJson(self::BASE.'/anuncio/3009/link', ['link' => 'https://kazakora.com/produtos/novo', 'confirmo_analise' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Link inválido') && str_contains($m, 'Nada foi alterado'));

        $this->assertCount(1, $this->posts());
    }

    public function test_anuncio_de_catalogo_nao_troca_link(): void
    {
        $this->actingAs($this->admin())
            ->putJson(self::BASE.'/anuncio/3005/link', ['link' => 'https://kazakora.com/produtos/novo', 'confirmo_analise' => true])
            ->assertStatus(422);

        $this->assertSame([], $this->posts());
    }

    public function test_anuncio_do_kazakora_grava_o_link_aqui_tambem(): void
    {
        $campanha = $this->campanhaLocal();

        $this->actingAs($this->admin())
            ->putJson(self::BASE.'/anuncio/3001/link', ['link' => 'https://kazakora.com/produtos/novo', 'confirmo_analise' => true])
            ->assertOk();

        $video = $campanha->videos->first()->fresh();
        $this->assertSame('https://kazakora.com/produtos/novo', $video->link_destino);
        $this->assertSame('7777', $video->meta_creative_id);
        $this->assertNull($video->edicao_pendente);
        $this->assertSame('3001', $this->posts()[1][0]);
    }

    public function test_status_ao_vivo_e_campanha_local_acompanha_a_meta(): void
    {
        $campanha = $this->campanhaLocal(MetaAdsCampanha::STATUS_ATIVA);

        $this->actingAs($this->admin())
            ->getJson(self::BASE.'/status')
            ->assertOk()
            ->assertJsonPath('meta.campanhas.1001', 'PAUSED')
            ->assertJsonPath('meta.anuncios.3009', 'PENDING_REVIEW')
            ->assertJsonPath('locais.0.status', MetaAdsCampanha::STATUS_ENVIADA_PAUSADA)
            ->assertJsonPath('locais.0.status_meta', 'PAUSED');

        $this->assertSame(MetaAdsCampanha::STATUS_ENVIADA_PAUSADA, $campanha->fresh()->status);
        $this->assertSame([], $this->posts());
    }

    public function test_status_nao_mexe_em_rascunho_nem_envio_em_andamento(): void
    {
        $campanha = $this->campanhaLocal(MetaAdsCampanha::STATUS_ENVIANDO);

        $this->actingAs($this->admin())->getJson(self::BASE.'/status')->assertOk();

        $this->assertSame(MetaAdsCampanha::STATUS_ENVIANDO, $campanha->fresh()->status);
    }

    public function test_criativo_com_link_novo_troca_em_todo_lugar(): void
    {
        $payload = MetaAdsService::criativoComLinkNovo([
            'name' => 'C',
            'object_story_spec' => ['link_data' => ['link' => 'https://a', 'image_hash' => 'h', 'picture' => 'https://p',
                'call_to_action' => ['type' => 'SHOP_NOW', 'value' => ['link' => 'https://a']]]],
            'asset_feed_spec' => ['link_urls' => [['website_url' => 'https://a', 'display_url' => 'kazakora.com']]],
        ], 'https://kazakora.com/produtos/b');

        $spec = json_decode($payload['object_story_spec'], true);
        $this->assertSame('https://kazakora.com/produtos/b', $spec['link_data']['link']);
        $this->assertSame('https://kazakora.com/produtos/b', $spec['link_data']['call_to_action']['value']['link']);
        $this->assertArrayNotHasKey('picture', $spec['link_data']);
        $this->assertSame(
            [['website_url' => 'https://kazakora.com/produtos/b', 'display_url' => 'kazakora.com']],
            json_decode($payload['asset_feed_spec'], true)['link_urls'],
        );
    }

    public function test_sem_permissao_nao_troca_link(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'customer']))
            ->putJson(self::BASE.'/anuncio/3009/link', ['link' => 'https://kazakora.com/produtos/novo', 'confirmo_analise' => true])
            ->assertForbidden();

        $this->assertSame([], $this->posts());
    }
}
