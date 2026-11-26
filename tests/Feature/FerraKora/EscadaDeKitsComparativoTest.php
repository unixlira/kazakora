<?php

namespace Tests\Feature\FerraKora;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAdContent;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Services\AnuncioConteudoService;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Oferta do Compressor 4 em 1, 26/11/2026 (pedidos do Lira): capa limpa de bundle na frente do
 * carrossel do kit B sem tirar os blocos da descrição da foto deles, comparativo com foto nos dois
 * lados de cada linha (FerraKora e KazaKora), a oferta também no KazaKora e a foto própria do kit
 * no cartão da escada.
 */
class EscadaDeKitsComparativoTest extends TestCase
{
    use RefreshDatabase;

    private string $pasta;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['frete_embutido.ativo' => false]);
        $this->pasta = database_path('escadas-kits/teste-comparativo-'.uniqid());
        mkdir($this->pasta.'/fotos', 0777, true);
        file_put_contents($this->pasta.'/fotos/capa-limpa.jpg', 'jpg');
        file_put_contents($this->pasta.'/fotos/comum.webp', 'webp');
        file_put_contents($this->pasta.'/fotos/produto.webp', 'webp');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->pasta.'/fotos/*') ?: [] as $arquivo) {
            unlink($arquivo);
        }
        @rmdir($this->pasta.'/fotos');
        @rmdir($this->pasta);
        foreach (glob($this->pasta.'*.json') ?: [] as $arquivo) {
            unlink($arquivo);
        }
        parent::tearDown();
    }

    /** @return array{0: Product, 1: Product} */
    private function kits(): array
    {
        $a = Product::factory()->create(['sku' => 'KIT-A', 'name' => 'Compressor', 'slug' => 'compressor', 'price' => 400, 'discount_percentage' => null, 'discount_amount' => 100, 'stock' => 5, 'is_active' => true, 'so_ferrakora' => true]);
        $b = Product::factory()->create(['sku' => 'KIT-B', 'name' => 'Kit Estrada', 'slug' => 'kit-estrada', 'price' => 600, 'discount_percentage' => null, 'discount_amount' => 300, 'stock' => 5, 'is_active' => true, 'so_ferrakora' => true]);
        foreach ([$a, $b] as $i => $p) {
            FerraKoraProduto::create(['product_id' => $p->id, 'ordem' => $i + 1, 'ativo' => true, 'categoria' => 'Automotivo']);
        }
        foreach (['b0.jpg', 'b1.jpg', 'b2.jpg'] as $i => $arquivo) {
            Storage::disk('public')->put("products/{$b->id}/{$arquivo}", 'x');
            ProductImage::create(['product_id' => $b->id, 'path' => "products/{$b->id}/{$arquivo}", 'position' => $i, 'is_primary' => $i === 0, 'is_variant' => false]);
        }
        Storage::disk('public')->put("products/{$a->id}/a0.jpg", 'x');
        ProductImage::create(['product_id' => $a->id, 'path' => "products/{$a->id}/a0.jpg", 'position' => 0, 'is_primary' => true, 'is_variant' => false]);
        ProductAdContent::create(['product_id' => $b->id, 'fonte' => 'claude', 'blocos' => [
            ['titulo' => 'Um', 'texto' => 'T', 'imagem' => 1],
            ['titulo' => 'Dois', 'texto' => 'T', 'imagem' => 2],
        ], 'beneficios' => [['Antigo', 'x']]]);

        return [$a, $b];
    }

    private function arquivo(string $sufixo, array $dados): string
    {
        $nome = basename($this->pasta).$sufixo;
        file_put_contents(database_path("escadas-kits/{$nome}.json"), json_encode($dados, JSON_UNESCAPED_UNICODE));

        return $nome;
    }

    private function rodar(Product $a, Product $b): void
    {
        $rel = basename($this->pasta).'/fotos/';
        $principal = $this->arquivo('-principal', ['escada' => ['titulo' => 'Escolha o seu kit', 'itens' => [
            ['sku' => 'KIT-A', 'nome' => 'Compressor 4 em 1', 'itens' => 'Compressor', 'foto' => "products/{$a->id}/limpa.jpg"],
            ['sku' => 'KIT-B', 'nome' => 'Kit Estrada Segura', 'itens' => 'Compressor + Reparo', 'destaque' => true],
        ]]]);
        $ajustes = $this->arquivo('-ajustes', [
            'ajustar' => [
                ['sku' => 'KIT-A', 'so_ferrakora' => false],
                ['sku' => 'KIT-B', 'so_ferrakora' => false, 'fotos_na_frente' => [['arquivo' => $rel.'capa-limpa.jpg']], 'conteudo' => [
                    'comparativo' => ['titulo' => 'Veja por que este kit é a melhor escolha', 'linhas' => [['Pneu baixo', 'Posto', 'Calibra ali']], 'imagens' => [[$rel.'comum.webp', $rel.'produto.webp']]],
                    'beneficios' => [['Novo', 'y']],
                ]],
            ],
            'escada' => $principal,
        ]);

        $this->artisan('kits:escada', ['nome' => $ajustes, '--aplicar' => true])->assertSuccessful();
        // De novo: a capa não entra duas vezes e os blocos não andam outra vez.
        $this->artisan('kits:escada', ['nome' => $ajustes, '--aplicar' => true])->assertSuccessful();
    }

    public function test_capa_na_frente_blocos_andam_juntos_e_comparativo_com_fotos(): void
    {
        [$a, $b] = $this->kits();
        $this->rodar($a, $b);

        $fotos = $b->images()->get();
        $this->assertSame("products/{$b->id}/kit/capa-limpa.jpg", $fotos[0]->path);
        $this->assertTrue($fotos[0]->is_primary);
        $this->assertSame(["products/{$b->id}/b0.jpg", "products/{$b->id}/b1.jpg", "products/{$b->id}/b2.jpg"], $fotos->slice(1)->pluck('path')->values()->all());
        $this->assertSame(1, $fotos->where('is_primary', true)->count());

        $conteudo = $b->adContent()->first();
        $this->assertSame([2, 3], array_column($conteudo->blocos, 'imagem'));
        $this->assertSame([['Novo', 'y']], $conteudo->beneficios);
        $this->assertSame([["products/{$b->id}/comparativo/comum.webp", "products/{$b->id}/comparativo/produto.webp"]], $conteudo->comparativo['imagens']);
        Storage::disk('public')->assertExists("products/{$b->id}/comparativo/produto.webp");
        // Escrito à mão: a IA não gera outro por cima.
        $this->assertFalse(app(AnuncioConteudoService::class)->precisaGerar($b->fresh()));

        $this->assertFalse($a->fresh()->so_ferrakora);
        $this->assertFalse($b->fresh()->so_ferrakora);
        $this->assertSame("products/{$a->id}/limpa.jpg", $b->fresh()->escada_kits['itens'][0]['foto']);
    }

    public function test_ferrakora_recebe_a_url_das_fotos_e_a_foto_propria_do_kit(): void
    {
        [$a, $b] = $this->kits();
        $this->rodar($a, $b);

        $produto = $this->getJson('/api/ferrakora/produtos/kz-'.$b->id)->assertOk()->json('product');

        $this->assertStringEndsWith("/storage/products/{$b->id}/kit/capa-limpa.jpg", $produto['images'][0]['url']);
        $this->assertStringEndsWith("/storage/products/{$b->id}/b1.jpg", $produto['conteudo']['blocos'][0]['imagem_url']);
        $this->assertStringEndsWith("/storage/products/{$b->id}/comparativo/comum.webp", $produto['conteudo']['comparativo']['imagens'][0][0]);
        $this->assertStringEndsWith("/storage/products/{$a->id}/limpa.jpg", $produto['kits']['itens'][0]['foto']['url']);
        $this->assertStringEndsWith("/storage/products/{$b->id}/kit/capa-limpa.jpg", $produto['kits']['itens'][1]['foto']['url']);
    }

    public function test_kazakora_mostra_a_escada_e_o_comparativo_com_fotos(): void
    {
        [$a, $b] = $this->kits();
        $this->rodar($a, $b);

        $this->get('/produtos/kit-estrada')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Catalog/ProductDetailV2', false)
                ->where('kits.itens.0.url', '/produtos/compressor')
                ->where('kits.itens.0.preco.por', 300)
                ->where('kits.itens.0.preco.de', 400)
                ->where('kits.itens.1.atual', true)
                ->where('kits.itens.1.preco.por', 300)
                ->where('kits.itens.1.foto.url', fn ($url) => str_ends_with($url, "/storage/products/{$b->id}/kit/capa-limpa.jpg"))
                ->where('product.ad_content.comparativo.imagens.0.1', fn ($url) => str_ends_with($url, "/storage/products/{$b->id}/comparativo/produto.webp")));
    }

    public function test_kit_so_do_ferrakora_fica_fora_da_escada_da_kazakora(): void
    {
        [$a, $b] = $this->kits();
        $this->rodar($a, $b);
        $a->fresh()->forceFill(['so_ferrakora' => true])->save();

        $this->get('/produtos/kit-estrada')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('kits', null));
    }
}
