<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use App\Modules\Marketplace\Models\AfiliadoProduto;
use App\Modules\Marketplace\Models\ProductChannelListing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Afiliados › Shopee (2026-10-26): grupos definidos pelo Lira, preço do clone
 * = custo × 1,5 e SKU + "-AF".
 */
class AfiliadosShopeeTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    public function test_lista_os_grupos_e_calcula_o_preco_com_50_por_cento_sobre_o_custo(): void
    {
        $brinquedos = Category::factory()->create(['name' => 'Brinquedos e Games']);
        $outra = Category::factory()->create(['name' => 'Ferramentas']);

        $bike = Product::factory()->create(['name' => 'Bike Spinning Ergométrica Profissional 120kg', 'sku' => 'BIKE-1', 'cost_price' => 380, 'category_id' => $outra->id]);
        $carrinho = Product::factory()->create(['name' => 'Carrinho', 'cost_price' => 19.99, 'category_id' => $brinquedos->id]);
        Product::factory()->create(['name' => 'Variação do carrinho', 'cost_price' => 10, 'category_id' => $brinquedos->id, 'parent_product_id' => $carrinho->id]);
        $algodao = Product::factory()->create(['name' => 'Máquina de Algodão Doce', 'cost_price' => 73, 'category_id' => $outra->id]);
        $pote = Product::factory()->create(['name' => 'Kit 6 Potes Herméticos', 'cost_price' => 30, 'category_id' => $outra->id]);
        $medidor = Product::factory()->create(['name' => 'Medidor de Pressão Arterial', 'cost_price' => 28, 'category_id' => $outra->id]);
        Product::factory()->create(['name' => 'Liquidificador', 'cost_price' => 50, 'category_id' => $outra->id]);

        // Ferramentas do FerraKora: só as ativas que não estão na Shopee.
        $semShopee = Product::factory()->create(['name' => 'Martelo', 'sku' => 'MART-1', 'cost_price' => 11, 'category_id' => $outra->id]);
        $naShopee = Product::factory()->create(['name' => 'Alicate', 'sku' => 'ALI-1', 'cost_price' => 12, 'category_id' => $outra->id]);
        $desativada = Product::factory()->create(['name' => 'Serrote', 'sku' => 'SER-1', 'cost_price' => 12, 'category_id' => $outra->id]);
        ProductChannelListing::create(['product_id' => $naShopee->id, 'channel' => 'shopee', 'external_id' => '123', 'status' => 'published']);
        foreach ([$semShopee, $naShopee, $desativada] as $i => $p) {
            FerraKoraProduto::create(['product_id' => $p->id, 'ativo' => $p->id !== $desativada->id, 'ordem' => $i]);
        }

        $this->actingAs($this->admin())->get('/admin/afiliados/shopee')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Admin/Afiliados/Shopee', false)
                ->where('contagem.bike', 1)
                ->where('contagem.brinquedos', 2)
                ->where('contagem.potes', 1)
                ->where('contagem.medidor', 1)
                ->where('contagem.ferramentas', 1)
                ->where('totais.sugeridos', 6)
                ->where('produtos', fn ($lista) => collect($lista)->pluck('id')->sort()->values()->all()
                    === collect([$bike, $carrinho, $algodao, $pote, $medidor, $semShopee])->pluck('id')->sort()->values()->all()));

        $this->get('/admin/afiliados/shopee?grupo=bike')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('produtos.0.preco_afiliado', 570)
                ->where('produtos.0.comissao', 57)
                ->where('produtos.0.sku_afiliado', 'BIKE-1-AF')
                ->where('produtos.0.grupo', 'bike'));

        $this->get('/admin/afiliados/shopee?grupo=brinquedos')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('produtos', fn ($lista) => collect($lista)->firstWhere('id', $carrinho->id)['preco_afiliado'] === 29.99));
    }

    public function test_marca_e_desmarca_um_produto_e_um_grupo(): void
    {
        $brinquedos = Category::factory()->create(['name' => 'Brinquedos e Games']);
        $a = Product::factory()->create(['name' => 'Boneca', 'sku' => 'BON-1', 'cost_price' => 40, 'category_id' => $brinquedos->id]);
        $b = Product::factory()->create(['name' => 'Pião', 'sku' => 'PIA-1', 'cost_price' => 10, 'category_id' => $brinquedos->id]);
        $semCusto = Product::factory()->create(['name' => 'Bola', 'cost_price' => null, 'category_id' => $brinquedos->id]);

        $this->actingAs($this->admin())->post('/admin/afiliados/shopee/escolher', ['produtos' => [$a->id], 'escolhido' => true])->assertRedirect();
        $this->assertDatabaseHas('afiliado_produtos', ['canal' => 'shopee', 'product_id' => $a->id, 'sku_afiliado' => 'BON-1-AF', 'custo' => 40, 'preco' => 60]);

        $this->post('/admin/afiliados/shopee/escolher', ['grupo' => 'brinquedos', 'escolhido' => true])->assertSessionHas('error');
        $this->assertSame(2, AfiliadoProduto::count());
        $this->assertDatabaseMissing('afiliado_produtos', ['product_id' => $semCusto->id]);

        $this->post('/admin/afiliados/shopee/escolher', ['produtos' => [$b->id], 'escolhido' => false]);
        $this->assertSame([$a->id], AfiliadoProduto::pluck('product_id')->all());

        $this->post('/admin/afiliados/shopee/escolher', ['grupo' => 'brinquedos', 'escolhido' => false]);
        $this->assertSame(0, AfiliadoProduto::count());
    }

    public function test_quem_nao_tem_permissao_de_editar_nao_escolhe(): void
    {
        $cliente = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $p = Product::factory()->create(['cost_price' => 10]);

        $this->actingAs($cliente)->post('/admin/afiliados/shopee/escolher', ['produtos' => [$p->id], 'escolhido' => true]);
        $this->assertSame(0, AfiliadoProduto::count());
    }
}
