<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Models\MetaAdsCampanha;
use App\Modules\Marketplace\Models\MetaAdsVideo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Meta ADS (2026-10-20): botão "Sincronizar com a Meta" traz para o banco o
 * que mudou direto no Gerenciador (nome, criativo e link de destino
 * corrigido). Só lê a Graph API falsa (Http::fake): nenhum POST na Meta.
 */
class MetaAdsSincronizarTest extends TestCase
{
    use RefreshDatabase;

    private const ROTA = '/admin/publicidade/meta-ads/campanhas/sincronizar';

    private const LINK_NOVO = 'https://kazakora.com/produtos/caixa-168-pecas';

    /** @var list<HttpRequest> */
    private array $chamadas = [];

    private array $respostas = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.meta_ads.access_token' => 'token-secreto',
            'services.meta_ads.ad_account_id' => 'act_123',
            'services.meta_ads.page_id' => '999',
            'services.meta_ads.graph_url' => 'https://graph.facebook.com/v23.0',
        ]);

        $criativo = fn (string $id, string $link) => [
            'id' => $id,
            'object_story_spec' => ['video_data' => [
                'video_id' => '4001', 'message' => 'Texto novo', 'title' => 'Caixa 168',
                'call_to_action' => ['type' => 'SHOP_NOW', 'value' => ['link' => $link]],
            ]],
        ];

        $this->respostas = [
            'act_123/campaigns' => [['id' => '1001', 'name' => 'Caixa 168 Peças', 'status' => 'PAUSED', 'daily_budget' => '4000']],
            'act_123/adsets' => [['id' => '2001', 'name' => 'Caixa 168 Peças | Homens 25-60', 'campaign_id' => '1001']],
            'act_123/ads' => [
                ['id' => '3001', 'name' => 'Criativo 1', 'adset_id' => '2001', 'effective_status' => 'ACTIVE', 'creative' => $criativo('5101', self::LINK_NOVO)],
                ['id' => '3002', 'name' => 'Caixa 168 Peças | Homens 25-60 | anúncio 2 (v1)', 'adset_id' => '2001', 'effective_status' => 'ACTIVE', 'creative' => $criativo('5002', self::LINK_NOVO)],
            ],
        ];

        Http::fake(function (HttpRequest $request) {
            $this->chamadas[] = $request;
            $caminho = Str::after(parse_url($request->url(), PHP_URL_PATH), '/v23.0/');

            return Http::response(['data' => $this->respostas[$caminho] ?? []]);
        });
    }

    private function campanha(): MetaAdsCampanha
    {
        $campanha = MetaAdsCampanha::create([
            'nome' => 'Caixa 85 Peças', 'objetivo' => 'OUTCOME_SALES', 'orcamento_tipo' => MetaAdsCampanha::ORCAMENTO_CAMPANHA, 'orcamento_diario' => 30,
            'inicio' => now()->toDateString(), 'idade_min' => 25, 'idade_max' => 60, 'genero' => 'todos',
            'link_destino' => 'https://kazakora.com/produtos/caixa-85-pecas', 'texto_principal' => 'Texto velho', 'titulo' => 'Caixa 85', 'chamada' => 'SHOP_NOW',
            'status' => MetaAdsCampanha::STATUS_ATIVA, 'meta_campaign_id' => '1001',
        ]);
        $conjunto = $campanha->conjuntos()->create(['nome' => 'Homens', 'ordem' => 0, 'meta_adset_id' => '2001']);
        foreach ([0 => '3001', 1 => '3002'] as $ordem => $ad) {
            $campanha->videos()->create([
                'conjunto_id' => $conjunto->id, 'ordem' => $ordem, 'nome_original' => "v{$ordem}.mp4", 'path' => "meta-ads/v{$ordem}.mp4", 'tamanho' => 1,
                'link_destino' => $ordem === 0 ? 'https://kazakora.com/produtos/caixa-85-pecas' : null,
                'meta_adset_id' => '2001', 'meta_ad_id' => $ad, 'meta_creative_id' => '500'.($ordem + 1), 'meta_video_id' => '4001',
            ]);
        }

        return $campanha;
    }

    public function test_traz_nomes_criativo_e_link_corrigido_da_meta(): void
    {
        $produto = Product::factory()->create(['slug' => 'caixa-168-pecas']);
        $campanha = $this->campanha();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->post(self::ROTA)
            ->assertRedirect()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'link(s) de destino corrigido(s)'));

        $campanha->refresh();
        $this->assertSame('Caixa 168 Peças', $campanha->nome);
        $this->assertSame(self::LINK_NOVO, $campanha->link_destino);
        $this->assertSame($produto->id, $campanha->produto_id);
        $this->assertSame('40.00', $campanha->orcamento_diario);
        $this->assertSame(MetaAdsCampanha::STATUS_ENVIADA_PAUSADA, $campanha->status);
        $this->assertSame('Homens 25-60', $campanha->conjuntos()->first()->nome);

        [$v0, $v1] = $campanha->videos()->orderBy('ordem')->get()->all();
        // Anúncio renomeado no Gerenciador e com criativo novo.
        $this->assertSame('Criativo 1', $v0->nome);
        $this->assertSame('5101', $v0->meta_creative_id);
        // O link próprio antigo vira o novo; o texto mudado lá vem junto.
        $this->assertSame(self::LINK_NOVO, $v0->link_destino);
        $this->assertSame('Texto novo', $v0->texto_principal);
        $this->assertSame('Caixa 168', $v0->criativo($campanha)['titulo']);
        // Nome automático igual ao da Meta: continua automático; link segue o padrão da campanha.
        $this->assertNull($v1->nome);
        $this->assertNull($v1->link_destino);
        $this->assertSame(self::LINK_NOVO, $v1->criativo($campanha)['link']);

        $this->assertSame([], array_filter($this->chamadas, fn (HttpRequest $r) => $r->method() !== 'GET'));
    }

    public function test_anuncio_com_troca_de_arquivo_pendente_fica_de_fora(): void
    {
        $campanha = $this->campanha();
        MetaAdsVideo::query()->where('meta_ad_id', '3001')->update(['edicao_pendente' => json_encode(['campos' => []])]);

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->post(self::ROTA)
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'ficou(aram) para depois'));

        $this->assertSame('5001', MetaAdsVideo::query()->where('meta_ad_id', '3001')->value('meta_creative_id'));
        $this->assertSame(self::LINK_NOVO, $campanha->fresh()->link_destino);
    }

    public function test_sem_permissao_nao_sincroniza(): void
    {
        $this->campanha();

        $this->actingAs(User::factory()->create(['role' => User::ROLE_CUSTOMER]))
            ->post(self::ROTA)
            ->assertForbidden();

        $this->assertSame([], $this->chamadas);
    }
}
