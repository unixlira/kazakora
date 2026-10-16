<?php

namespace Tests\Feature\FerraKora;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Catálogo do FerraKora vindo do KazaKora (pedido 2026-10-13): mesmo
 * formato do Beauty Kora, com categoria e link do produto na loja.
 */
class CatalogoFerraKoraTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-de-teste';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ferrakora.catalogo_token' => self::TOKEN]);
    }

    private function grupo(): array
    {
        $pai = Product::factory()->create([
            'sku' => 'FER-TRE-5M', 'slug' => 'trena-emborrachada-5m', 'name' => 'Trena Emborrachada 5m Cor Amarela', 'variation' => 'Cor Amarela',
            'description' => "Trena de 5 metros.\nTrava automática.", 'price' => 39.90, 'stock' => 8, 'is_active' => true,
            'discount_percentage' => null, 'discount_amount' => null,
        ]);
        $filho = Product::factory()->create([
            'sku' => 'FER-TRE-5M-PR', 'name' => 'Trena Emborrachada 5m Cor Preta', 'variation' => 'Cor Preta', 'parent_product_id' => $pai->id,
            'price' => 39.90, 'stock' => 0, 'is_active' => true, 'discount_percentage' => null, 'discount_amount' => null,
        ]);

        return [$pai, $filho];
    }

    private function enviar(array $produtos, string $token = self::TOKEN)
    {
        return $this->postJson('/api/ferrakora/produtos', ['produtos' => $produtos], ['Authorization' => 'Bearer '.$token]);
    }

    public function test_recusa_sem_token(): void
    {
        $this->grupo();

        $this->enviar([['sku' => 'FER-TRE-5M']], 'errado')->assertStatus(401);
        $this->assertSame(0, FerraKoraProduto::count());
    }

    public function test_sku_da_variacao_liga_o_pai_com_categoria_e_link_da_loja(): void
    {
        [$pai, $filho] = $this->grupo();

        $this->enviar([['sku' => 'FER-TRE-5M-PR', 'categoria' => 'Trenas', 'ordem' => 1]])
            ->assertOk()
            ->assertJsonPath('gravados.0.id', 'kz-'.$pai->id)
            ->assertJsonPath('gravados.0.variacoes', 2)
            ->assertJsonPath('gravados.0.categoria', 'Trenas');

        $produto = $this->getJson('/api/ferrakora/produtos')->assertOk()->assertJsonPath('count', 1)->json('products.0');

        $this->assertSame('kz-'.$pai->id, $produto['id']);
        $this->assertSame('Trena Emborrachada 5m', $produto['title']);
        // A lista sai sem descrição e sem "media"; a página do produto tem tudo.
        $this->assertArrayNotHasKey('description', $produto);
        $this->assertArrayNotHasKey('media', $produto);
        $this->assertArrayHasKey('thumbnail_leve', $produto);
        $this->assertSame('Cor', $produto['options'][0]['title']);
        $this->assertSame('kzv-'.$filho->id, $produto['variants'][1]['id']);
        $this->assertSame(3990, $produto['variants'][0]['prices'][0]['amount']);
        $this->assertNull($produto['variants'][0]['prices'][0]['sale_amount']);
        $this->assertSame('Trenas', $produto['categoria']);
        $this->assertSame(route('produtos.ver', 'trena-emborrachada-5m'), $produto['kazakora_url']);
        $this->assertStringStartsWith('http', $produto['kazakora_url']);

        $this->getJson('/api/ferrakora/produtos/kz-'.$pai->id)
            ->assertOk()
            ->assertJsonPath('product.categoria', 'Trenas')
            ->assertJsonPath('product.description', '<p>Trena de 5 metros.</p><p>Trava automática.</p>');
        $this->getJson('/api/ferrakora/produtos/trena-emborrachada-5m')->assertOk()->assertJsonPath('product.id', 'kz-'.$pai->id);
        $this->getJson('/api/ferrakora/produtos/nao-existe')->assertNotFound();
    }

    public function test_foto_leva_a_miniatura_leve_e_a_media(): void
    {
        [$pai] = $this->grupo();
        ProductImage::query()->create([
            'product_id' => $pai->id, 'path' => 'products/'.$pai->id.'/foto.jpg', 'position' => 0, 'is_primary' => true,
            'thumb_path' => 'products/'.$pai->id.'/thumbs/foto.webp', 'medium_path' => 'products/'.$pai->id.'/medias/foto.webp',
        ]);
        $this->enviar([['sku' => 'FER-TRE-5M', 'categoria' => 'Trenas']])->assertOk();

        $produto = $this->getJson('/api/ferrakora/produtos')->assertOk()->json('products.0');

        $this->assertStringEndsWith('/storage/products/'.$pai->id.'/foto.jpg', $produto['thumbnail']);
        $this->assertStringEndsWith('/storage/products/'.$pai->id.'/thumbs/foto.webp', $produto['thumbnail_leve']);
        $this->assertStringEndsWith('/thumbs/foto.webp', $produto['images'][0]['thumb_url']);
        $this->assertStringEndsWith('/medias/foto.webp', $produto['images'][0]['media_url']);
    }

    public function test_sem_categoria_sai_null_e_nao_aparece_no_beauty_kora(): void
    {
        $this->grupo();

        $this->enviar([['sku' => 'FER-TRE-5M']])->assertOk();

        $this->getJson('/api/ferrakora/produtos')->assertOk()->assertJsonPath('products.0.categoria', null);
        $this->getJson('/api/beautykora/produtos')->assertOk()->assertJsonPath('count', 0);
    }

    public function test_sku_inexistente_nao_grava_nada(): void
    {
        $this->grupo();

        $this->enviar([['sku' => 'FER-TRE-5M', 'categoria' => 'Trenas'], ['sku' => 'NAO-EXISTE']])
            ->assertStatus(422)
            ->assertJsonPath('nao_encontrados', ['NAO-EXISTE']);

        $this->assertSame(0, FerraKoraProduto::count());
    }

    public function test_rota_de_visita_continua_recusando_origem_estranha(): void
    {
        $this->postJson('/api/ferrakora/visita', ['path' => '/'], ['Origin' => 'https://site-estranho.com'])
            ->assertForbidden();
    }
}
