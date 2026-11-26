<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Títulos persuasivos (pedido do Lira 25/11/2026): o título novo pode não ter
 * a palavra que o cliente busca; o nome antigo fica em termos_busca e a busca
 * do site, do topo, do admin e da API continua achando o produto.
 */
class TermosBuscaTest extends TestCase
{
    use RefreshDatabase;

    private function produto(): Product
    {
        return Product::factory()->create([
            'name' => 'Cozinha Sempre Limpa Sem Cheiro no Ar',
            'termos_busca' => 'Lixeira Automática com Sensor 12 L',
            'is_active' => true,
            'stock' => 10,
            'price' => 99,
        ]);
    }

    public function test_busca_do_site_acha_pelo_termos_busca(): void
    {
        $produto = $this->produto();
        Product::factory()->create(['name' => 'Trena de Aço', 'is_active' => true]);

        $ids = collect($this->get('/?search=lixeira')->viewData('page')['props']['products']['data'])->pluck('id')->all();

        $this->assertSame([$produto->id], $ids);
    }

    public function test_busca_instantanea_do_topo_acha_pelo_termos_busca(): void
    {
        $produto = $this->produto();

        $this->getJson('/busca/sugestoes?q=lixeira sensor')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('produtos.0.slug', $produto->slug);
    }

    public function test_listagem_do_admin_leva_os_termos_para_a_busca_da_tela(): void
    {
        $produto = $this->produto();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/admin/produtos')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('products.0.id', $produto->id)
            ->where('products.0.termos_busca', 'Lixeira Automática com Sensor 12 L'));
    }
}
