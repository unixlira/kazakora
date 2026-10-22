<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Admin\Models\CriativoValidacao;
use App\Modules\Catalog\Models\Product;
use App\Modules\Marketplace\Models\MetaAdsCampanha;
use App\Modules\Marketplace\Models\MetaAdsVideo;
use App\Services\Meta\MetaAdsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Meta ADS (2026-10-21, pedido do Lira): o criativo do anúncio vem "Do
 * computador" (upload, como antes) ou "Dentro da plataforma": vídeo ou
 * imagem aprovado em Validação de criativos, copiado no servidor e enviado
 * à Meta como qualquer outro anúncio.
 */
class MetaAdsCriativoDaPlataformaTest extends TestCase
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

    private function criativo(string $extensao, string $status = CriativoValidacao::APROVADO, array $extra = []): CriativoValidacao
    {
        $path = 'criativos/2026-10/'.Str::uuid().'.'.$extensao;
        Storage::disk('local')->put($path, 'conteudo-'.$extensao.'-'.Str::random(6));

        return CriativoValidacao::create($extra + [
            'titulo' => 'Árvore de Natal '.$extensao,
            'plataforma' => 'meta',
            'nome_original' => 'arvore.'.$extensao,
            'path' => $path,
            'tamanho' => 2048,
            'largura' => 1080,
            'altura' => 1920,
            'status' => $status,
        ]);
    }

    private function campanha(): MetaAdsCampanha
    {
        $campanha = MetaAdsCampanha::create([
            'nome' => 'Natal', 'objetivo' => 'OUTCOME_TRAFFIC', 'orcamento_diario' => 30,
            'inicio' => now()->addDay()->toDateString(), 'idade_min' => 18, 'idade_max' => 65, 'genero' => 'todos',
            'localizacoes' => ['regioes' => [], 'cidades' => []],
            'link_destino' => 'https://kazakora.com/', 'texto_principal' => 'Árvore de Natal', 'titulo' => 'Árvore',
            'chamada' => 'SHOP_NOW', 'status' => MetaAdsCampanha::STATUS_RASCUNHO,
        ]);
        $campanha->conjuntos()->create(['nome' => 'Conjunto 1', 'ordem' => 0]);

        return $campanha->load('conjuntos');
    }

    public function test_lista_so_os_aprovados_com_os_da_meta_primeiro(): void
    {
        $tiktok = $this->criativo('mp4', extra: ['titulo' => 'TikTok aprovado', 'plataforma' => 'tiktok']);
        $imagem = $this->criativo('png');
        $this->criativo('mp4', CriativoValidacao::AGUARDANDO);
        $this->criativo('mp4', CriativoValidacao::REPROVADO);
        $this->criativo('png', extra: ['tamanho' => 31 * 1048576]); // imagem acima do limite da Meta

        $this->actingAs($this->admin())
            ->getJson(self::BASE.'/criativos-aprovados')
            ->assertOk()
            ->assertJsonCount(2, 'criativos')
            ->assertJsonPath('criativos.0.id', $imagem->id)
            ->assertJsonPath('criativos.0.tipo', 'imagem')
            ->assertJsonPath('criativos.1.id', $tiktok->id)
            ->assertJsonPath('criativos.1.tipo', 'video');

        $this->actingAs($this->admin())
            ->getJson(self::BASE.'/criativos-aprovados?busca=TikTok')
            ->assertJsonCount(1, 'criativos');
    }

    public function test_previa_so_de_criativo_aprovado(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(self::BASE.'/criativos-aprovados/'.$this->criativo('mp4')->id.'/arquivo')->assertOk();
        $this->actingAs($admin)->get(self::BASE.'/criativos-aprovados/'.$this->criativo('mp4', CriativoValidacao::AGUARDANDO)->id.'/arquivo')->assertNotFound();
    }

    public function test_criativo_aprovado_vira_anuncio_e_vai_para_a_meta(): void
    {
        $admin = $this->admin();
        $campanha = $this->campanha();
        $video = $this->criativo('mp4');
        $imagem = $this->criativo('jpg');
        $produto = Product::factory()->create(['slug' => 'arvore-de-natal', 'is_active' => true]);

        $this->actingAs($admin)
            ->postJson(self::BASE."/{$campanha->id}/videos/da-plataforma", [
                'criativo_id' => $video->id, 'conjunto_id' => $campanha->conjuntos[0]->id, 'produto_id' => $produto->id,
            ])
            ->assertCreated()
            ->assertJsonPath('video.tipo', MetaAdsVideo::TIPO_VIDEO)
            ->assertJsonPath('video.nome', 'Árvore de Natal mp4.mp4');
        $this->actingAs($admin)
            ->postJson(self::BASE."/{$campanha->id}/videos/da-plataforma", ['criativo_id' => $imagem->id])
            ->assertCreated()
            ->assertJsonPath('video.tipo', MetaAdsVideo::TIPO_IMAGEM);

        [$anuncioVideo, $anuncioImagem] = $campanha->videos()->orderBy('ordem')->get()->all();
        $disco = Storage::disk('local');
        $this->assertStringStartsWith("meta-ads/campanha-{$campanha->id}/", $anuncioVideo->path);
        $this->assertSame($disco->get($video->path), $disco->get($anuncioVideo->path));
        $this->assertTrue($disco->exists($video->path), 'O criativo original continua na validação.');
        $this->assertSame('https://kazakora.com/produtos/arvore-de-natal', $anuncioVideo->link_destino);
        $this->assertSame(1080, $anuncioVideo->largura);

        $corpos = [];
        Http::fake(function (HttpRequest $request) use (&$corpos) {
            $caminho = Str::after(parse_url($request->url(), PHP_URL_PATH), '/v23.0/');
            if (in_array($caminho, ['act_123/advideos', 'act_123/adimages'], true)) {
                $corpos[$caminho] = $request->body();
            }

            return match (true) {
                $caminho === 'act_123/campaigns' => Http::response(['id' => 'camp1']),
                $caminho === 'act_123/advideos' => Http::response(['id' => 'vid1']),
                $caminho === 'act_123/adimages' => Http::response(['images' => ['x.jpg' => ['hash' => 'hash1', 'url' => 'https://scontent.example/img.jpg']]]),
                str_ends_with($caminho, '/thumbnails') => Http::response(['data' => [['uri' => 'https://scontent.example/capa.jpg', 'is_preferred' => true]]]),
                str_starts_with($caminho, 'vid') => Http::response(['status' => ['video_status' => 'ready']]),
                $caminho === 'act_123/adsets' => Http::response(['id' => 'set1']),
                $caminho === 'act_123/adcreatives' => Http::response(['id' => 'cri'.Str::random(4)]),
                $caminho === 'act_123/ads' => Http::response(['id' => 'ad'.Str::random(4)]),
                default => Http::response(['success' => true]),
            };
        });

        $meta = app(MetaAdsService::class);
        for ($i = 0; $i < 20 && $meta->avancarEnvio($campanha->fresh(['conjuntos', 'videos'])) !== MetaAdsService::ENVIO_PRONTO; $i++);

        $this->assertStringContainsString($disco->get($video->path), $corpos['act_123/advideos']);
        $this->assertStringContainsString($disco->get($imagem->path), $corpos['act_123/adimages']);
        $this->assertTrue($anuncioVideo->fresh()->envioCompleto());
        $this->assertSame('hash1', $anuncioImagem->fresh()->meta_image_hash);
    }

    public function test_recusa_criativo_que_nao_esta_aprovado(): void
    {
        $campanha = $this->campanha();

        $this->actingAs($this->admin())
            ->postJson(self::BASE."/{$campanha->id}/videos/da-plataforma", ['criativo_id' => $this->criativo('mp4', CriativoValidacao::AJUSTAR)->id])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Este criativo não está mais aprovado. Escolha outro.');

        $this->assertSame(0, $campanha->videos()->count());
    }

    public function test_validacao_de_criativos_aceita_imagem(): void
    {
        $admin = $this->admin();
        $png = UploadedFile::fake()->image('banner.png', 1080, 1350);
        $conteudo = file_get_contents($png->getRealPath());
        $envio = (string) Str::uuid();

        $this->actingAs($admin)
            ->post('/admin/validacao-criativos/parte', [
                'envio' => $envio, 'inicio' => 0, 'parte' => UploadedFile::fake()->createWithContent('parte.bin', $conteudo),
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $this->actingAs($admin)
            ->postJson('/admin/validacao-criativos', [
                'envio' => $envio, 'nome_original' => 'banner.png', 'tamanho' => strlen($conteudo),
                'titulo' => 'Banner Natal', 'plataforma' => 'meta',
            ])
            ->assertCreated()
            ->assertJsonPath('criativo.tipo', 'imagem')
            ->assertJsonPath('criativo.proporcao', '4:5');

        $criativo = CriativoValidacao::sole();
        $this->assertNull($criativo->duracao_segundos);
        $this->assertSame([1080, 1350], [$criativo->largura, $criativo->altura]);
    }
}
