<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Support\TituloPtBr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Departamentos enxutos e nomes em título pt-BR (pedido 2026-10-10). */
class ReorganizarDepartamentosTest extends TestCase
{
    use RefreshDatabase;

    public function test_nome_de_categoria_vira_titulo_pt_br(): void
    {
        $this->assertSame('Organização de Cozinha', TituloPtBr::formatar('ORGANIZAÇÃO DE COZINHA'));
        $this->assertSame('Utensílios e Dia a Dia', TituloPtBr::formatar('utensílios e dia a dia'));
        $this->assertSame('Cabos USB e LED', TituloPtBr::formatar('cabos usb e led'));
        $this->assertSame('Beleza e Bem-Estar', Category::factory()->create(['name' => 'beleza e bem-estar'])->name);
    }

    public function test_simulacao_nao_muda_e_aplicar_reorganiza_sem_deixar_produto_sem_categoria(): void
    {
        Storage::fake('local');
        $cat = fn (string $nome, string $slug) => Category::factory()->create(['name' => $nome, 'slug' => $slug]);
        $eletronicos = $cat('Eletrônicos', 'eletronicos');
        $organizacao = $cat('ORGANIZAÇAO COZINHA', 'organizacao-cozinha');
        $cozinha = $cat('Cozinha', 'cozinha');
        $ferramentas = $cat('Ferramentas', 'ferramentas');
        $brinquedos = $cat('Brinquedos e Hobbies', 'brinquedos-e-hobbies');
        $beleza = $cat('Produtos de beleza', 'produtos-de-beleza');
        $cat('Pets', 'pets');

        $lanterna = Product::factory()->create(['name' => 'Lanterna De Cabeça Led', 'category_id' => $eletronicos->id]);
        $pote = Product::factory()->create(['name' => 'Kit 3 Organizadores Geladeira', 'category_id' => $organizacao->id]);
        $semCategoria = Product::factory()->create(['name' => 'Câmera Wi-Fi 4K Externa', 'category_id' => null]);
        $brinquedo = Product::factory()->create(['name' => 'Brinquedo Cozinha Pia Lava-Louças com Carrinhos', 'category_id' => $brinquedos->id]);
        $variacao = Product::factory()->create(['name' => 'Preto', 'category_id' => null, 'parent_product_id' => $lanterna->id]);

        $this->artisan('loja:reorganizar-departamentos')->assertSuccessful();
        $this->assertSame($eletronicos->id, $lanterna->fresh()->category_id);
        $this->assertSame(7, Category::count());

        $this->artisan('loja:reorganizar-departamentos', ['--aplicar' => true])->assertSuccessful();

        $this->assertSame(
            ['Beleza e Bem-Estar', 'Brinquedos e Games', 'Casa e Cozinha', 'Eletrônicos e Acessórios', 'Ferramentas e Automotivo'],
            Category::query()->orderBy('name')->pluck('name')->all(),
        );
        $nome = fn (Product $p) => $p->fresh()->category->name;
        $this->assertSame('Ferramentas e Automotivo', $nome($lanterna));
        $this->assertSame('Ferramentas e Automotivo', $nome($variacao));
        $this->assertSame('Casa e Cozinha', $nome($pote));
        $this->assertSame('Eletrônicos e Acessórios', $nome($semCategoria));
        $this->assertSame('Brinquedos e Games', $nome($brinquedo));
        $this->assertSame(0, Product::query()->whereNull('category_id')->count());
        $this->assertSame($cozinha->id, Category::query()->where('slug', 'casa-e-cozinha')->value('id'));
        $this->assertNotEmpty(Storage::disk('local')->files('backups'));
    }
}
