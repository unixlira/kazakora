<?php

namespace Tests\Feature\Loja;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductSlugAntigo;
use App\Modules\Catalog\Support\MarcaDaLoja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Produto fora da vitrine da KazaKora sem desativar (macaco elétrico repetido, Lira 26/11/2026). */
class ForaDaVitrineKzTest extends TestCase
{
    use RefreshDatabase;

    public function test_comando_tira_da_vitrine_redireciona_com_utm_e_soma_avaliacoes(): void
    {
        $velho = Product::factory()->create(['slug' => 'macaco-velho', 'is_active' => true]);
        $novo = Product::factory()->create(['slug' => 'kit-macaco-novo', 'is_active' => true]);
        ProductSlugAntigo::create(['slug' => 'macaco-mais-velho', 'product_id' => $velho->id]);

        $this->artisan('loja:fora-da-vitrine', ['produto' => $velho->id, '--para' => $novo->id, '--somar-avaliacoes' => 'Macaco'])
            ->assertSuccessful();

        $velho->refresh();
        $this->assertTrue($velho->is_active, 'continua ativo para o marketplace');
        $this->assertFalse($velho->naKazaKora());
        $this->assertNotContains($velho->id, Product::query()->naKazaKora()->pluck('id')->all());
        $this->assertSame([['id' => $velho->id, 'rotulo' => 'Macaco']], $novo->fresh()->avaliacoes_de);

        $this->get('/produtos/macaco-velho?fbclid=x&utm_source=teste')
            ->assertStatus(301)
            ->assertRedirect('/produtos/kit-macaco-novo?fbclid=x&utm_source=teste');
        $this->get('/produtos/macaco-mais-velho?utm_source=teste')
            ->assertStatus(301)
            ->assertRedirect('/produtos/kit-macaco-novo?utm_source=teste');
        $this->get('/sitemap.xml')->assertDontSee('macaco-velho');

        $this->artisan('loja:fora-da-vitrine', ['produto' => $velho->id, '--desfazer' => true])->assertSuccessful();
        $this->assertTrue($velho->fresh()->naKazaKora());
        $this->assertNull($novo->fresh()->avaliacoes_de);
    }

    public function test_marca_da_loja_troca_o_texto_e_nao_mexe_em_endereco(): void
    {
        $this->assertSame('Qualidade premium KazaKora em kazakora.com', MarcaDaLoja::paraKazaKora('Qualidade premium FerraKora em ferrakora.com'));
        $this->assertSame(['titulo' => 'Compressor FerraKora', 'imagem_url' => 'https://x/kazakora/a.jpg'],
            MarcaDaLoja::paraFerraKora(['titulo' => 'Compressor KazaKora', 'imagem_url' => 'https://x/kazakora/a.jpg']));
        $this->assertSame('/storage/ferrakora/a.mp4', MarcaDaLoja::paraKazaKora('/storage/ferrakora/a.mp4'));
        $this->assertNull(MarcaDaLoja::paraKazaKora(null));
    }
}
