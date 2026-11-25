<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Publicidade › Régua de CPA (2026-11-25): a tela recebe os produtos ativos
 * com custo e as taxas oficiais da loja; quem não é admin não entra.
 */
class ReguaCpaTest extends TestCase
{
    use RefreshDatabase;

    public function test_entrega_produtos_com_custo_e_taxas_da_loja(): void
    {
        config([
            'ofertas.taxa_cartao' => 4.98,
            'ofertas.taxa_pix' => 0.99,
            'ofertas.frete_estimado' => 25,
            'loja.simples.aliquota' => 4.5,
            'loja.insumos_por_pedido' => 2.0,
        ]);
        $travesseiro = Product::factory()->create(['name' => 'Travesseiro', 'price' => 187.46, 'cost_price' => 80, 'is_active' => true]);
        Product::factory()->create(['name' => 'Sem custo', 'cost_price' => 0, 'is_active' => true]);
        Product::factory()->create(['name' => 'Inativo', 'cost_price' => 10, 'is_active' => false]);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $this->actingAs($admin)->get('/admin/publicidade/regua-cpa')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/ReguaCpa/Index')
                ->has('produtos', 1)
                ->where('produtos.0.id', $travesseiro->id)
                ->where('produtos.0.custo', 80)
                ->where('produtos.0.preco', 187.46)
                ->where('taxas.cartao', 4.98)
                ->where('taxas.pix', 0.99)
                ->where('taxas.imposto', 4.5)
                ->where('taxas.insumos', 2)
                ->where('taxas.frete', 25)
                ->where('taxas.boleto_fixo', 3.49));
    }

    public function test_cliente_nao_entra(): void
    {
        $cliente = User::factory()->create();
        $this->actingAs($cliente)->get('/admin/publicidade/regua-cpa')->assertStatus(403);
    }
}
