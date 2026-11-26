<?php

namespace Tests\Feature\Loja;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\CartoesDeOpcoes;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Opções do anúncio em cartões no formato dos kits do livro (pedido do Lira 26/11/2026, Compressor 4 em 1). */
class CartoesDeOpcoesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Product, 1: Product, 2: Product} */
    private function anuncio(): array
    {
        $pai = Product::factory()->create(['slug' => 'compressor-4-em-1', 'variation' => 'Compressor 4 em 1', 'stock' => 10, 'is_active' => true]);
        $kit = Product::factory()->create(['slug' => 'kit-estrada', 'variation' => 'Kit Estrada Segura', 'stock' => 10, 'is_active' => true, 'parent_product_id' => $pai->id]);
        $macaco = Product::factory()->create(['slug' => 'kit-macaco', 'variation' => 'Kit Macaco', 'stock' => 10, 'is_active' => true, 'parent_product_id' => $pai->id]);

        return [$pai, $kit, $macaco];
    }

    private function cartoes(int ...$ids): array
    {
        return ['titulo' => 'Escolha a sua opção', 'itens' => array_map(fn (int $id, int $i) => [
            'id' => $id, 'nome' => "Opção {$i}", 'itens' => 'o que vem', 'vantagens' => ['Calibra o pneu', 'Dá partida'], 'destaque' => $i === 1,
        ], $ids, array_keys($ids))];
    }

    public function test_pagina_sem_cartoes_continua_com_o_seletor_de_sempre(): void
    {
        [$pai] = $this->anuncio();

        $this->get('/produtos/'.$pai->slug)->assertOk()
            ->assertInertia(fn (AssertableInertia $pagina) => $pagina->where('cartoesOpcoes', null));
    }

    public function test_pagina_traz_os_cartoes_so_das_opcoes_que_ela_tem(): void
    {
        [$pai, $kit, $macaco] = $this->anuncio();
        $outro = Product::factory()->create();
        $pai->forceFill(['cartoes_opcoes' => $this->cartoes($pai->id, $kit->id, $macaco->id, $outro->id)])->save();

        $this->get('/produtos/'.$pai->slug)->assertOk()
            ->assertInertia(fn (AssertableInertia $pagina) => $pagina
                ->where('cartoesOpcoes.titulo', 'Escolha a sua opção')
                ->where('cartoesOpcoes.itens', fn ($itens) => collect($itens)->pluck('id')->all() === [$pai->id, $kit->id, $macaco->id]
                    && $itens[1]['destaque'] === true && $itens[0]['vantagens'] === ['Calibra o pneu', 'Dá partida']));
    }

    public function test_api_da_ferrakora_manda_os_cartoes_pela_variante(): void
    {
        [$pai, $kit, $macaco] = $this->anuncio();
        FerraKoraProduto::create(['product_id' => $pai->id, 'ordem' => 1, 'ativo' => true, 'categoria' => 'Automotivo']);
        $pai->forceFill(['cartoes_opcoes' => $this->cartoes($pai->id, $kit->id, $macaco->id)])->save();

        $produto = $this->getJson('/api/ferrakora/produtos/kz-'.$pai->id)->assertOk()->json('product');

        $this->assertSame(['kzv-'.$pai->id, 'kzv-'.$kit->id, 'kzv-'.$macaco->id], array_column($produto['cartoes_opcoes']['itens'], 'id'));
    }

    public function test_problemas_e_comando(): void
    {
        [$pai, $kit] = $this->anuncio();
        $this->assertContains('palavra proibida: frete', CartoesDeOpcoes::problemas(['itens' => [['id' => 1, 'nome' => 'Frete'], ['id' => 2, 'nome' => 'b']]]));
        $this->assertContains('precisa de pelo menos 2 opções', CartoesDeOpcoes::problemas(['itens' => [['id' => 1, 'nome' => 'a']]]));
        $this->assertNull(CartoesDeOpcoes::paraPagina($pai, [$pai->id]));

        $this->artisan('loja:cartoes-opcoes', ['nome' => 'compressor-4-em-1'])->assertFailed();
    }
}
