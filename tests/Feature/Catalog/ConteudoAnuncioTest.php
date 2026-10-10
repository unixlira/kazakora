<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAdContent;
use App\Modules\Catalog\Services\AnuncioConteudoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Conteúdo do anúncio (pedido 2026-10-09): benefícios + descrição em blocos
 * gerados pelo Gemini; se ele falhar (conexão, cota, JSON ruim), a regra
 * própria monta tudo pela descrição e a página nunca fica sem conteúdo.
 */
class ConteudoAnuncioTest extends TestCase
{
    use RefreshDatabase;

    private const DESCRICAO = "Kit Com 3 Cestos Organizadores De Bambu:\nDeixe sua casa muito mais organizada e charmosa.\nOrganize Qualquer Ambiente:\nVersáteis e funcionais, servem no banheiro, quarto e cozinha.\nPrincipais Benefícios:\n- Kit com 3 cestos organizadores\n- Produzidos em bambu natural\n- Forro interno em tecido\n- Resistentes e duráveis";

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

    private function produto(): Product
    {
        $product = Product::factory()->create(['name' => 'Kit Cestos', 'description' => self::DESCRICAO, 'is_active' => true]);
        foreach ([0, 1, 2] as $i) {
            $product->images()->create(['path' => "products/x/{$i}.jpg", 'position' => $i, 'is_primary' => $i === 0]);
        }

        return $product->fresh();
    }

    private function respostaGemini(array $json): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => "```json\n".json_encode($json)."\n```"]]]]], 'usageMetadata' => []];
    }

    public function test_gemini_monta_o_conteudo_e_escolhe_as_imagens(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->respostaGemini([
            'destaques' => ['Organiza qualquer cômodo', 'Bambu natural', 'Forro removível'],
            'chamada' => 'Sua casa organizada com charme',
            'blocos' => [
                ['titulo' => 'Chega de bagunça', 'texto' => 'Três tamanhos para guardar tudo.', 'imagem' => 2],
                ['titulo' => 'Bambu que dura', 'texto' => 'Resistente e bonito.', 'imagem' => 9],
            ],
            'comparativo' => ['titulo' => 'Comparado ao comum', 'intro' => 'Veja.', 'linhas' => [['Visual', 'Plástico sem graça', 'Bambu natural']]],
            'beneficios' => [['Mais organização', 'tudo no lugar']],
            'duvidas' => [['Serve no banheiro?', 'Sim, a descrição indica uso no banheiro.']],
        ]))]);

        $conteudo = app(AnuncioConteudoService::class)->gerar($this->produto());

        $this->assertSame(ProductAdContent::FONTE_GEMINI, $conteudo->fonte);
        $this->assertSame('Sua casa organizada com charme', $conteudo->chamada);
        $this->assertSame(2, $conteudo->blocos[0]['imagem']);
        $this->assertNull($conteudo->blocos[1]['imagem'], 'índice de imagem inexistente é descartado');
        $this->assertCount(3, $conteudo->destaques);
        $this->assertSame('Bambu natural', $conteudo->comparativo['linhas'][0][2]);
        $this->assertNull($conteudo->erro);
    }

    public function test_erro_de_conexao_cai_na_regra_automatica_sem_quebrar(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $conteudo = app(AnuncioConteudoService::class)->gerar($this->produto());

        $this->assertSame(ProductAdContent::FONTE_AUTOMATICO, $conteudo->fonte);
        $this->assertNotNull($conteudo->erro);
        $this->assertSame('Kit Com 3 Cestos Organizadores De Bambu', $conteudo->blocos[0]['titulo']);
        $this->assertSame('Deixe sua casa muito mais organizada e charmosa.', $conteudo->blocos[0]['texto']);
        $this->assertSame(1, $conteudo->blocos[0]['imagem']);
        $this->assertSame(2, $conteudo->blocos[1]['imagem']);
        $this->assertSame(['Kit com 3 cestos organizadores', 'Produzidos em bambu natural', 'Forro interno em tecido'], $conteudo->destaques);
        $this->assertCount(4, $conteudo->beneficios);
    }

    public function test_json_ruim_do_gemini_tambem_cai_na_regra_automatica(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'desculpe, não consigo']]]]]])]);

        $conteudo = app(AnuncioConteudoService::class)->gerar($this->produto());

        $this->assertSame(ProductAdContent::FONTE_AUTOMATICO, $conteudo->fonte);
        $this->assertNotEmpty($conteudo->blocos);
    }

    public function test_so_gera_de_novo_quando_o_produto_muda(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $servico = app(AnuncioConteudoService::class);
        $product = $this->produto();

        $servico->gerar($product);
        $this->assertFalse($servico->precisaGerar($product->fresh()));

        $product->update(['description' => self::DESCRICAO."\nNovo parágrafo."]);
        $this->assertTrue($servico->precisaGerar($product->fresh()));
    }

    public function test_comando_gera_para_produtos_ativos_sem_conteudo(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $product = $this->produto();
        Product::factory()->create(['is_active' => false, 'description' => 'x']);

        $this->artisan('produtos:gerar-conteudo-anuncio')->assertSuccessful();

        $this->assertSame(1, ProductAdContent::count());
        $this->assertNotNull($product->fresh()->adContent);
    }

    public function test_pagina_do_produto_recebe_o_conteudo(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $product = $this->produto();
        app(AnuncioConteudoService::class)->gerar($product);

        $this->get("/produtos/{$product->slug}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Catalog/ProductDetailV2', false)
                ->where('product.ad_content.fonte', 'automatico')
                ->has('product.ad_content.blocos', 2)
                ->missing('product.ad_content.erro'));
    }

    public function test_admin_gera_de_novo_pelo_botao(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $product = $this->produto();

        $this->actingAs($admin)->post("/admin/produtos/{$product->id}/conteudo-anuncio")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNotNull($product->fresh()->adContent);
    }
}
