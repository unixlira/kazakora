<?php

namespace Tests\Feature\BeautyKora;

use App\Modules\BeautyKora\Models\BeautyKoraProduto;
use App\Modules\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Catálogo do Beauty Kora vindo do KazaKora (pedido 2026-10-12): o JSON
 * enviado liga produtos do KazaKora ao site, que lê no formato da vitrine
 * da Hostinger.
 */
class CatalogoBeautyKoraTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-de-teste';

    protected function setUp(): void
    {
        parent::setUp();
        // Sem frete embutido: aqui conta o formato; a região está em PrecoRegiaoBeautyKoraTest.
        config(['services.beautykora.catalogo_token' => self::TOKEN, 'frete_embutido.ativo' => false]);
    }

    private function grupo(): array
    {
        $pai = Product::factory()->create([
            'sku' => 'BEL-BLU-T01', 'name' => 'Blush Líquido 10ml Tom 01', 'variation' => 'Tom 01',
            'description' => "Blush líquido.\nAcabamento natural.", 'price' => 28.85, 'stock' => 10, 'is_active' => true,
            'discount_percentage' => null, 'discount_amount' => null,
        ]);
        $filho = Product::factory()->create([
            'sku' => 'BEL-BLU-T02', 'name' => 'Blush Líquido 10ml Tom 02', 'variation' => 'Tom 02', 'parent_product_id' => $pai->id,
            'price' => 28.85, 'stock' => 0, 'is_active' => true, 'discount_percentage' => null, 'discount_amount' => null,
        ]);

        return [$pai, $filho];
    }

    private function enviar(array $produtos, string $token = self::TOKEN)
    {
        return $this->postJson('/api/beautykora/produtos', ['produtos' => $produtos], ['Authorization' => 'Bearer '.$token]);
    }

    public function test_recusa_sem_token(): void
    {
        $this->grupo();

        $this->enviar([['sku' => 'BEL-BLU-T01']], 'errado')->assertStatus(401);
        $this->assertSame(0, BeautyKoraProduto::count());
    }

    public function test_sku_da_variacao_liga_o_pai_e_o_site_le_no_formato_da_vitrine(): void
    {
        [$pai, $filho] = $this->grupo();

        $this->enviar([['sku' => 'BEL-BLU-T02', 'ordem' => 1]])
            ->assertOk()
            ->assertJsonPath('gravados.0.id', 'kz-'.$pai->id)
            ->assertJsonPath('gravados.0.variacoes', 2);

        $produto = $this->getJson('/api/beautykora/produtos')->assertOk()->assertJsonPath('count', 1)->json('products.0');

        $this->assertSame('kz-'.$pai->id, $produto['id']);
        $this->assertSame('Blush Líquido 10ml', $produto['title']);
        $this->assertSame('<p>Blush líquido.</p><p>Acabamento natural.</p>', $produto['description']);
        $this->assertSame('Tom', $produto['options'][0]['title']);
        $this->assertSame(['Tom 01', 'Tom 02'], array_column($produto['options'][0]['values'], 'value'));
        $this->assertSame('kzv-'.$filho->id, $produto['variants'][1]['id']);
        // Clonado por padrão: 28,85 + 5% = 30,29.
        $this->assertSame(3029, $produto['variants'][0]['prices'][0]['amount']);
        $this->assertNull($produto['variants'][0]['prices'][0]['sale_amount']);
        $this->assertSame(0, $produto['variants'][1]['inventory_quantity']);

        $this->getJson('/api/beautykora/produtos/kz-'.$pai->id)->assertOk()->assertJsonPath('product.slug', 'blush-liquido-10ml');
        $this->getJson('/api/beautykora/produtos/blush-liquido-10ml')->assertOk()->assertJsonPath('product.id', 'kz-'.$pai->id);
    }

    public function test_sku_inexistente_nao_grava_nada(): void
    {
        $this->grupo();

        $this->enviar([['sku' => 'BEL-BLU-T01'], ['sku' => 'NAO-EXISTE']])
            ->assertStatus(422)
            ->assertJsonPath('nao_encontrados', ['NAO-EXISTE']);

        $this->assertSame(0, BeautyKoraProduto::count());
    }

    public function test_inativo_sai_do_site(): void
    {
        [$pai] = $this->grupo();

        $this->enviar([['sku' => 'BEL-BLU-T01']])->assertOk();
        $this->enviar([['sku' => 'BEL-BLU-T01', 'ativo' => false]])->assertOk();

        $this->getJson('/api/beautykora/produtos')->assertOk()->assertJsonPath('count', 0);
        $this->getJson('/api/beautykora/produtos/kz-'.$pai->id)->assertNotFound();
    }
}
