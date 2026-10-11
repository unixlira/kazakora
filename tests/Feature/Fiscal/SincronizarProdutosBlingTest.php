<?php

namespace Tests\Feature\Fiscal;

use App\Modules\Catalog\Models\Product;
use App\Services\Bling\BlingClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/** bling:sincronizar-produtos (pedido 2026-10-11). */
class SincronizarProdutosBlingTest extends TestCase
{
    use RefreshDatabase;

    private function produto(string $sku): Product
    {
        $produto = Product::factory()->create(['sku' => $sku, 'is_active' => true, 'price' => 99.9]);
        $produto->fiscalData()->create([
            'ncm' => '8507.60.00', 'origem' => 2, 'unidade_tributavel' => 'UN',
            'peso_bruto' => 0.35, 'altura_cm' => 5, 'largura_cm' => 10, 'profundidade_cm' => 15,
        ]);

        return $produto;
    }

    public function test_cria_o_que_falta_completa_o_vazio_e_nao_mexe_em_duplicado(): void
    {
        $this->produto('NOVO-1');
        $this->produto('EXISTE-1');
        $this->produto('DUP-1');

        $bling = Mockery::mock(BlingClient::class);
        $bling->shouldReceive('get')->with('produtos', ['codigo' => 'NOVO-1'])->andReturn(['data' => []]);
        $bling->shouldReceive('get')->with('produtos', ['codigo' => 'EXISTE-1'])->andReturn(['data' => [['id' => 10, 'codigo' => 'EXISTE-1'], ['id' => 11, 'codigo' => 'EXISTE-10']]]);
        $bling->shouldReceive('get')->with('produtos', ['codigo' => 'DUP-1'])->andReturn(['data' => [['id' => 20, 'codigo' => 'DUP-1'], ['id' => 21, 'codigo' => 'DUP-1']]]);
        $bling->shouldReceive('get')->with('produtos/10')->andReturn(['data' => [
            'id' => 10, 'codigo' => 'EXISTE-1', 'unidade' => 'UN', 'pesoBruto' => 0,
            'tributacao' => ['ncm' => '', 'origem' => 0],
        ]]);

        $bling->shouldReceive('post')->once()->withArgs(fn ($uri, $dados) => $uri === 'produtos'
            && $dados['codigo'] === 'NOVO-1' && $dados['tributacao']['ncm'] === '85076000'
            && $dados['pesoBruto'] === 0.35 && $dados['dimensoes']['profundidade'] === 15.0)->andReturn(['data' => ['id' => 99]]);
        $bling->shouldReceive('put')->once()->withArgs(fn ($uri, $dados) => $uri === 'produtos/10'
            && $dados['tributacao']['ncm'] === '85076000' && $dados['pesoBruto'] === 0.35)->andReturn([]);

        $this->app->instance(BlingClient::class, $bling);

        $this->artisan('bling:sincronizar-produtos --aplicar')
            ->expectsOutputToContain('1 criado(s), 1 completado(s), 0 já certo(s), 1 duplicado(s)')
            ->assertSuccessful();
    }

    public function test_sem_aplicar_nao_grava_nada(): void
    {
        $this->produto('NOVO-1');

        $bling = Mockery::mock(BlingClient::class);
        $bling->shouldReceive('get')->andReturn(['data' => []]);
        $bling->shouldNotReceive('post');
        $bling->shouldNotReceive('put');
        $this->app->instance(BlingClient::class, $bling);

        $this->artisan('bling:sincronizar-produtos')->expectsOutputToContain('seria criado')->assertSuccessful();
    }
}
