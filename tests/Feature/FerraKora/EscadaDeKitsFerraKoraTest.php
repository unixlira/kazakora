<?php

namespace Tests\Feature\FerraKora;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Review;
use App\Modules\Catalog\Support\EscadaDeKits;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Escada de kits entre produtos diferentes no FerraKora (pedido do Lira 26/11/2026,
 * Compressor 4 em 1 em 3 kits): cada página traz os cartões de todos, com preço,
 * estoque e foto de cada produto; kits com SKU de preço fixo mostram o mesmo
 * "de/por" em qualquer região.
 */
class EscadaDeKitsFerraKoraTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Product, 1: Product, 2: Product} */
    private function escada(): array
    {
        config(['frete_embutido.ativo' => false, 'frete_embutido.skus_preco_fixo' => ['KIT-B', 'KIT-C']]);
        $a = Product::factory()->create(['sku' => 'KIT-A', 'name' => 'Compressor 4 em 1', 'slug' => 'compressor', 'price' => 416, 'discount_percentage' => null, 'discount_amount' => 199, 'stock' => 10, 'is_active' => true]);
        $b = Product::factory()->create(['sku' => 'KIT-B', 'name' => 'Kit Estrada Segura', 'slug' => 'kit-estrada', 'price' => 629, 'discount_percentage' => null, 'discount_amount' => 331.10, 'stock' => 10, 'is_active' => true]);
        $c = Product::factory()->create(['sku' => 'KIT-C', 'name' => 'Kit Macaco Elétrico', 'slug' => 'kit-macaco', 'price' => 1299, 'discount_percentage' => null, 'discount_amount' => 569, 'stock' => 0, 'is_active' => true]);
        foreach ([$a, $b, $c] as $i => $p) {
            FerraKoraProduto::create(['product_id' => $p->id, 'ordem' => $i + 1, 'ativo' => true, 'categoria' => 'Automotivo']);
        }
        $escada = ['titulo' => 'Escolha o seu kit', 'itens' => [
            ['id' => $a->id, 'nome' => 'Compressor 4 em 1', 'itens' => 'Compressor', 'partes' => ['Compressor']],
            ['id' => $b->id, 'nome' => 'Kit Estrada Segura', 'itens' => 'Compressor + Reparo + Catraca', 'partes' => ['Compressor', 'Reparo', 'Catraca'], 'destaque' => true, 'selo' => 'Recomendado'],
            ['id' => $c->id, 'nome' => 'Kit Macaco Elétrico 5t', 'itens' => 'Macaco + chave de impacto'],
        ]];
        foreach ([$a, $b, $c] as $p) {
            $p->forceFill(['escada_kits' => $escada])->save();
        }

        return [$a, $b, $c];
    }

    public function test_pagina_traz_os_cartoes_de_todos_os_kits_com_preco_e_estoque(): void
    {
        [$a, $b, $c] = $this->escada();

        $kits = $this->getJson('/api/ferrakora/produtos/kz-'.$a->id)->assertOk()->json('product.kits');

        $this->assertSame('Escolha o seu kit', $kits['titulo']);
        $this->assertSame(['kz-'.$a->id, 'kz-'.$b->id, 'kz-'.$c->id], array_column($kits['itens'], 'id'));
        $this->assertTrue($kits['itens'][0]['atual']);
        $this->assertFalse($kits['itens'][1]['atual']);
        $this->assertSame(['de' => 62900, 'por' => 29790], $kits['itens'][1]['preco']);
        $this->assertSame(['de' => 129900, 'por' => 73000], $kits['itens'][2]['preco']);
        $this->assertTrue($kits['itens'][1]['destaque']);
        $this->assertSame('Recomendado', $kits['itens'][1]['selo']);
        $this->assertFalse($kits['itens'][2]['disponivel']);
        $this->assertSame('kit-estrada-segura', $kits['itens'][1]['slug']);

        // A página do kit B também mostra a escada, com ele marcado.
        $this->app->forgetScopedInstances();
        $doB = $this->getJson('/api/ferrakora/produtos/kz-'.$b->id)->assertOk()->json('product.kits.itens');
        $this->assertTrue($doB[1]['atual']);
    }

    public function test_kit_desligado_sai_da_escada_e_sem_dois_kits_nao_ha_escada(): void
    {
        [$a, $b, $c] = $this->escada();
        $b->update(['is_active' => false]);
        $c->update(['is_active' => false]);

        $this->getJson('/api/ferrakora/produtos/kz-'.$a->id)->assertOk()->assertJsonPath('product.kits', null);
    }

    public function test_preco_fixo_pelo_sku_nao_soma_o_frete_da_regiao_no_ferrakora(): void
    {
        [, $b] = $this->escada();
        config(['frete_embutido.ativo' => true]);

        $preco = $this->getJson('/api/ferrakora/produtos/kz-'.$b->id.'?simular_uf=AM')->assertOk()->json('product.variants.0.prices.0');

        $this->assertSame(62900, $preco['amount']);
        $this->assertSame(29790, $preco['sale_amount']);
    }

    public function test_kit_junta_as_avaliacoes_dos_produtos_que_vem_nele(): void
    {
        [$a, $b] = $this->escada();
        $b->forceFill(['avaliacoes_de' => [['id' => $a->id, 'rotulo' => 'Compressor 4 em 1']]])->save();
        Review::create(['product_id' => $a->id, 'rating' => 5, 'comment' => 'Calibra rápido', 'reviewer_name' => 'Ana']);

        $this->getJson('/api/ferrakora/produtos/kz-'.$b->id.'/avaliacoes')->assertOk()->assertJsonPath('count', 1);
    }

    public function test_problemas_barram_palavras_proibidas_e_dois_destaques(): void
    {
        $problemas = EscadaDeKits::problemas(['itens' => [
            ['id' => 1, 'nome' => 'Kit com frete grátis', 'destaque' => true],
            ['id' => 2, 'nome' => 'Kit 2', 'destaque' => true],
        ]]);

        $this->assertContains('palavra proibida: frete', $problemas);
        $this->assertContains('mais de um kit em destaque', $problemas);
        $this->assertSame([], EscadaDeKits::problemas(['itens' => [['id' => 1, 'nome' => 'A'], ['id' => 2, 'nome' => 'B']]]));
    }
}
