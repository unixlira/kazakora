<?php

namespace Tests\Feature\BeautyKora;

use App\Modules\BeautyKora\Models\BeautyKoraProdutoConteudo;
use App\Modules\BeautyKora\Services\ConteudoProdutoBeautyKora;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Página de produto do Beauty Kora (pedido 2026-10-12): o produto vem da
 * vitrine da Hostinger e o conteúdo (blocos com imagem, comparativo,
 * benefícios, dúvidas) sai do mesmo Gemini da KazaKora, com regra própria
 * se ele falhar.
 */
class ConteudoProdutoBeautyKoraTest extends TestCase
{
    use RefreshDatabase;

    private const ID = 'prod_01KRE789H28SPYAHXF3KX2GBKC';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.gemini.api_key' => 'chave-de-teste',
            'services.gemini.chat_model' => 'gemini-teste',
            'services.gemini.fallback_models' => [],
            'services.gemini.retry_delay_ms' => 0,
        ]);
    }

    private function produtoHostinger(string $descricao = '<p>Essência com <strong>96% de mucina</strong> de caracol.</p><p><strong>Benefícios:</strong></p><ul><li><p>Hidrata</p></li><li><p>Repara a pele</p></li></ul>'): array
    {
        return ['product' => [
            'id' => self::ID,
            'title' => 'Essência COSRX Snail 96',
            'description' => $descricao,
            'thumbnail' => 'https://cdn.exemplo.com/0.webp',
            'media' => [
                ['url' => 'https://cdn.exemplo.com/1.jpg', 'type' => 'image', 'order' => 1],
                ['url' => 'https://cdn.exemplo.com/0.webp', 'type' => 'image', 'order' => 0],
            ],
        ]];
    }

    private function respostaGemini(): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => json_encode([
            'destaques' => ['Hidratação profunda', 'Repara a pele', '96% de mucina'],
            'chamada' => 'Pele macia e iluminada',
            'blocos' => [['titulo' => 'Repara de verdade', 'texto' => 'A mucina ajuda a pele a se recuperar.', 'imagem' => 1]],
            'beneficios' => [['Hidrata', 'por mais tempo']],
            'duvidas' => [['Serve para pele sensível?', 'A descrição indica uso diário.']],
        ])]]]]], 'usageMetadata' => []];
    }

    public function test_gera_pelo_gemini_com_as_fotos_da_hostinger_e_guarda(): void
    {
        Http::fake([
            'api-ecommerce.hostinger.com/*' => Http::response($this->produtoHostinger()),
            'cdn.exemplo.com/*' => Http::response("\xFF\xD8\xFFfoto"),
            'generativelanguage.googleapis.com/*' => Http::response($this->respostaGemini()),
        ]);

        $this->getJson('/api/beautykora/produtos/'.self::ID.'/conteudo')
            ->assertOk()
            ->assertJsonPath('imagens', ['https://cdn.exemplo.com/0.webp', 'https://cdn.exemplo.com/1.jpg'])
            ->assertJsonPath('conteudo.fonte', 'gemini')
            ->assertJsonPath('conteudo.chamada', 'Pele macia e iluminada')
            ->assertJsonPath('conteudo.blocos.0.imagem', 1)
            ->assertJsonMissingPath('conteudo.erro');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'generativelanguage')
            && str_contains($request->body(), 'Beauty Kora')
            && str_contains($request->body(), '- Hidrata')
            && str_contains($request->body(), 'inline_data'));

        // Segunda visita, sem mudança no produto: não chama o Gemini de novo.
        Http::fake([
            'api-ecommerce.hostinger.com/*' => Http::response($this->produtoHostinger()),
            'generativelanguage.googleapis.com/*' => Http::response([], 500),
        ]);
        $this->getJson('/api/beautykora/produtos/'.self::ID.'/conteudo')->assertJsonPath('conteudo.fonte', 'gemini');
        $this->assertSame(1, BeautyKoraProdutoConteudo::query()->count());
    }

    public function test_gemini_fora_cai_na_regra_automatica(): void
    {
        Http::fake([
            'api-ecommerce.hostinger.com/*' => Http::response($this->produtoHostinger()),
            'cdn.exemplo.com/*' => Http::response('x'),
            'generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'cota']], 429),
        ]);

        $this->getJson('/api/beautykora/produtos/'.self::ID.'/conteudo')
            ->assertOk()
            ->assertJsonPath('conteudo.fonte', 'automatico')
            ->assertJsonPath('conteudo.beneficios.0.0', 'Hidrata');
    }

    public function test_produto_que_nao_existe_na_loja_da_404_sem_gastar_gemini(): void
    {
        Http::fake(['api-ecommerce.hostinger.com/*' => Http::response(['message' => 'not found'], 404)]);

        $this->getJson('/api/beautykora/produtos/prod_01XXXXXXXXXXXXXXXXXX/conteudo')->assertNotFound();
        $this->getJson('/api/beautykora/produtos/qualquer-coisa/conteudo')->assertNotFound();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'generativelanguage'));
    }

    public function test_descricao_em_html_vira_texto_com_itens(): void
    {
        $this->assertSame(
            "Essência com 96% de mucina de caracol.\nBenefícios:\n- Hidrata\n- Repara a pele",
            ConteudoProdutoBeautyKora::textoDoHtml($this->produtoHostinger()['product']['description']),
        );
    }

    public function test_comando_gera_todos_os_produtos_da_vitrine(): void
    {
        Http::fake([
            'api-ecommerce.hostinger.com/store/*/products?*' => Http::response(['products' => [['id' => self::ID]]]),
            'api-ecommerce.hostinger.com/*' => Http::response($this->produtoHostinger()),
            'cdn.exemplo.com/*' => Http::response('x'),
            'generativelanguage.googleapis.com/*' => Http::response($this->respostaGemini()),
        ]);

        $this->artisan('beautykora:gerar-conteudo')->expectsOutputToContain(self::ID.' → gemini')->assertSuccessful();
        $this->assertDatabaseHas('beautykora_produto_conteudos', ['produto_id' => self::ID, 'fonte' => 'gemini']);
    }
}
