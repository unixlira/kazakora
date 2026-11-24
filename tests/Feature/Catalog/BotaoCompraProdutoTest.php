<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\AnuncioConteudoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Botão principal de compra com o texto de cada produto (pedido do Lira
 * 2026-11-24): "Quero meu Livro Falante" no lugar do "Comprar agora".
 */
class BotaoCompraProdutoTest extends TestCase
{
    use RefreshDatabase;

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

    private function respostaGemini(array $json): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => json_encode($json)]]]]], 'usageMetadata' => []];
    }

    public function test_pagina_usa_o_texto_do_botao_do_produto(): void
    {
        $product = Product::factory()->create(['is_active' => true, 'cta_compra' => 'Quero meu Livro Falante']);

        $this->get("/produtos/{$product->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Catalog/ProductDetailV2', false)
                ->where('product.texto_botao_compra', 'Quero meu Livro Falante'));
    }

    public function test_sem_texto_a_pagina_cai_no_comprar_agora(): void
    {
        $product = Product::factory()->create(['is_active' => true, 'cta_compra' => null]);

        $this->get("/produtos/{$product->slug}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('product.texto_botao_compra', null));

        // O componente troca o nulo por "Comprar agora".
        $vue = file_get_contents(resource_path('js/Modules/Catalog/ProductDetailV2.vue'));
        $this->assertStringContainsString("props.product.texto_botao_compra || 'Comprar agora'", $vue);
        $this->assertStringNotContainsString('>COMPRAR AGORA<', $vue);
    }

    public function test_variacao_sem_texto_usa_o_do_produto_pai(): void
    {
        $pai = Product::factory()->create(['is_active' => true, 'cta_compra' => 'Quero minha Luminária']);
        $filha = Product::factory()->create(['is_active' => true, 'parent_product_id' => $pai->id, 'cta_compra' => null]);

        $this->assertSame('Quero minha Luminária', $filha->textoBotaoCompra());
    }

    public function test_regra_propria_acerta_o_genero_e_o_tamanho(): void
    {
        $this->assertSame('Quero meu Livro Falante', AnuncioConteudoService::botaoCompraPorRegra('Livro Falante Infantil Interativo 6 Idiomas'));
        $this->assertSame('Quero minha Luminária agora', AnuncioConteudoService::botaoCompraPorRegra('Luminária de Mesa LED Recarregável'));
        $this->assertSame('Quero meu Kit agora', AnuncioConteudoService::botaoCompraPorRegra('Kit Com 3 Cestos Organizadores De Bambu'));
        $this->assertSame('Quero meus Cestos agora', AnuncioConteudoService::botaoCompraPorRegra('Cestos de Bambu - 3 unidades'));
        // Nome da loja: frase de efeito antes do ":" e "+ PDF Grátis" no fim.
        $this->assertSame('Quero minha Lanterna agora', AnuncioConteudoService::botaoCompraPorRegra('Mãos Livres no Escuro: Lanterna de Cabeça LED T6 Recarregável + PDF Grátis'));
        $this->assertSame('Quero meu Power Bank agora', AnuncioConteudoService::botaoCompraPorRegra('Carga de Emergência no Bolso: Power Bank 5000mAh iPhone + PDF Grátis'));

        foreach (['Livro Falante', 'Panela Elétrica Multifuncional Gigante', 'Mop'] as $nome) {
            $this->assertLessThanOrEqual(28, mb_strlen(AnuncioConteudoService::botaoCompraPorRegra($nome)));
        }
    }

    public function test_ia_gera_e_texto_fora_da_regra_cai_na_regra_propria(): void
    {
        $livro = Product::factory()->create(['name' => 'Livro Falante Infantil', 'is_active' => true]);
        $panela = Product::factory()->create(['name' => 'Panela de Pressão Elétrica', 'is_active' => true]);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->respostaGemini(['botoes' => [
            (string) $livro->id => 'Quero meu Livro Falante 📚',
            (string) $panela->id => 'Quero minha Panela com frete grátis',
        ]]))]);

        $textos = app(AnuncioConteudoService::class)->textosBotaoCompra([$livro, $panela]);

        $this->assertSame(['texto' => 'Quero meu Livro Falante', 'fonte' => 'gemini'], $textos[$livro->id], 'emoji sai');
        $this->assertSame('automatico', $textos[$panela->id]['fonte'], 'frete/grátis/longo cai na regra');
        $this->assertSame('Quero minha Panela agora', $textos[$panela->id]['texto']);
    }

    public function test_preencher_nao_sobrescreve_texto_escrito_no_cadastro(): void
    {
        Http::fake();
        $product = Product::factory()->create(['name' => 'Livro Falante', 'cta_compra' => 'Quero o meu já']);

        app(AnuncioConteudoService::class)->preencherBotaoCompra($product);

        $this->assertSame('Quero o meu já', $product->fresh()->cta_compra);
        Http::assertNothingSent();
    }

    public function test_comando_dry_run_lista_sem_gravar_e_sem_dry_run_grava(): void
    {
        config(['services.gemini.api_key' => null]);
        $livro = Product::factory()->create(['name' => 'Livro Falante Infantil', 'is_active' => true, 'cta_compra' => null]);
        $comTexto = Product::factory()->create(['name' => 'Luminária LED', 'is_active' => true, 'cta_compra' => 'Quero minha Luminária']);
        $soFerraKora = Product::factory()->create(['name' => 'Furadeira de Impacto', 'is_active' => true, 'so_ferrakora' => true]);
        $inativo = Product::factory()->create(['name' => 'Mop Giratório', 'is_active' => false]);

        $this->artisan('produtos:gerar-cta', ['--dry-run' => true])
            ->expectsOutputToContain('Quero meu Livro Falante')
            ->expectsOutputToContain('1 produto(s) seriam gravados')
            ->assertSuccessful();

        $this->assertNull($livro->fresh()->cta_compra);

        $this->artisan('produtos:gerar-cta')->assertSuccessful();

        $this->assertSame('Quero meu Livro Falante', $livro->fresh()->cta_compra);
        $this->assertSame('Quero minha Luminária', $comTexto->fresh()->cta_compra);
        $this->assertNull($soFerraKora->fresh()->cta_compra);
        $this->assertNull($inativo->fresh()->cta_compra);
    }

    public function test_admin_grava_o_texto_e_recusa_texto_comprido(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $product = Product::factory()->create();
        $dados = ['name' => $product->name, 'price' => 99.9, 'is_active' => true];

        $this->actingAs($admin)->put("/admin/produtos/{$product->id}", [...$dados, 'cta_compra' => str_repeat('a', 29)])
            ->assertSessionHasErrors('cta_compra');

        $this->actingAs($admin)->put("/admin/produtos/{$product->id}", [...$dados, 'cta_compra' => ' Quero meu Livro Falante '])
            ->assertSessionHasNoErrors();

        $this->assertSame('Quero meu Livro Falante', $product->fresh()->cta_compra);
    }
}
