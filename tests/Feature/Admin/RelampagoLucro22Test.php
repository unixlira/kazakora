<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\OfertaRelampago;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\LucroDaRelampago;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Oferta Relâmpago com 22% de lucro líquido (pedido do Lira em 27/11/2026).
 *
 * Conta do produto padrão (preço 200, custo 80, sem Pix), frete R$ 25,
 * insumos R$ 2, imposto 4,5%, cartão 4,98%:
 *   venda = (80 + 25 + 2) / (1 − 0,0498 − 0,045 − 0,22) = 107 / 0,6852 = 156,16
 *   anúncio = 156,16 − 25 = 131,16 → desconto 34,42% (para baixo)
 */
class RelampagoLucro22Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['loja.simples.aliquota' => 4.5, 'loja.insumos_por_pedido' => 2.0, 'ofertas.taxa_cartao' => 4.98, 'ofertas.taxa_pix' => 0.99, 'ofertas.frete_estimado' => 25]);
    }

    private function produto(float $preco = 200, float $custo = 80, float $pix = 0): Product
    {
        return Product::factory()->create(['price' => $preco, 'cost_price' => $custo, 'stock' => 10, 'is_active' => true, 'checkout_discount_percentage' => $pix, 'discount_percentage' => null, 'discount_amount' => null]);
    }

    public function test_desconto_que_deixa_22_por_cento_de_lucro_liquido(): void
    {
        $conta = LucroDaRelampago::precoParaMargem($this->produto());

        $this->assertSame(34.42, $conta['desconto_pct']);
        $this->assertSame(131.16, $conta['preco_oferta']);
        $this->assertGreaterThanOrEqual(22.0, $conta['margem']);
        $this->assertLessThan(22.05, $conta['margem']);
    }

    public function test_margem_nunca_fica_abaixo_de_22_em_varios_precos(): void
    {
        foreach ([[59.9, 18], [99.9, 30], [149.9, 61.37], [249.9, 120], [589, 233.33], [1299, 510]] as [$preco, $custo]) {
            foreach ([0, 5] as $pix) {
                $produto = $this->produto($preco, $custo, $pix);
                $conta = LucroDaRelampago::precoParaMargem($produto);
                if ($conta === null) {
                    continue;
                }
                $this->assertGreaterThanOrEqual(22.0, LucroDaRelampago::conta($produto, $conta['preco_oferta'])['margem'], "preço {$preco}, custo {$custo}, pix {$pix}");
                // A oferta grava o mesmo preço que a conta usou.
                $oferta = new OfertaRelampago(['desconto_pct' => $conta['desconto_pct']]);
                $this->assertSame($conta['preco_oferta'], $oferta->precoPara($produto));
            }
        }
    }

    public function test_pix_do_produto_entra_no_pior_caso(): void
    {
        $semPix = LucroDaRelampago::precoParaMargem($this->produto());
        $comPix = LucroDaRelampago::precoParaMargem($this->produto(pix: 5));

        $this->assertLessThan($semPix['desconto_pct'], $comPix['desconto_pct']);
    }

    public function test_sem_custo_ou_sem_espaco_para_desconto_fica_null(): void
    {
        $this->assertNull(LucroDaRelampago::precoParaMargem($this->produto(custo: 0)));
        // Custo 80 a 120: nem o preço cheio dá 22%.
        $this->assertNull(LucroDaRelampago::precoParaMargem($this->produto(preco: 120)));
    }

    public function test_admin_calcula_o_desconto_de_22_por_cento(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $produto = $this->produto();

        $this->actingAs($admin)->getJson("/admin/ofertas-relampago/lucro-22?product_id={$produto->id}")->assertOk()
            ->assertJsonPath('desconto_pct', 34.42)
            ->assertJsonPath('preco_oferta', 131.16);

        $this->actingAs($admin)->getJson("/admin/ofertas-relampago/previa?product_id={$produto->id}&desconto_pct=34.42")->assertOk()
            ->assertJsonPath('produto.margem_oferta', fn ($m) => $m >= 22);

        $semCusto = $this->produto(custo: 0);
        $this->actingAs($admin)->getJson("/admin/ofertas-relampago/lucro-22?product_id={$semCusto->id}")->assertStatus(422);
    }
}
