<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Calculadora de preços (regra do Lira de 28/10/2026): a tela recebe o custo
 * e o tamanho de cada produto e o frete Correios por tamanho, tirado da
 * tabela de frete da loja (menor entre PAC e SEDEX). A conta fica na tela.
 */
class PricingCalculatorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function cotacao(string $cep, string $categoria, ?float $pac, ?float $sedex): void
    {
        DB::table('frete_tabela')->insert([
            'cep_destino' => $cep, 'categoria' => $categoria, 'cep_origem' => '03187040',
            'pac_preco' => $pac, 'sedex_preco' => $sedex, 'cotado_em' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_frete_correios_por_tamanho_usa_o_menor_entre_pac_e_sedex(): void
    {
        $this->cotacao('01310000', 'pequena', 20.00, 18.00); // SP: SEDEX mais barato
        $this->cotacao('13000000', 'pequena', 22.00, null);  // SP só com PAC
        $this->cotacao('69000000', 'pequena', 70.00, 90.00); // AM
        $this->cotacao('29820000', 'pequena', null, null);   // sem cotação: fica de fora
        $this->cotacao('69000000', 'media', 95.00, 80.00);

        Product::factory()->create(['name' => 'Ring Light', 'cost_price' => 13.30, 'categoria_frete' => 'media']);

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->get('/admin/precificacao')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/PricingCalculator/Index', false)
                ->where('fretes.pequena.sp', 20)
                ->where('fretes.pequena.brasil', 36.67)
                ->where('fretes.pequena.pior', 70)
                ->where('fretes.media.sp', null)
                ->where('fretes.media.brasil', 80)
                ->where('products.0.categoria_frete', 'media')
                ->has('categorias', 4));
    }
}
