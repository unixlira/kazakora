<?php

namespace Tests\Feature\FerraKora;

use App\Modules\BeautyKora\Models\BeautyKoraProduto;
use App\Modules\Catalog\Models\OutroSiteSlugAntigo;
use App\Modules\Catalog\Models\Product;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Endereço do produto no FerraKora (pedido do Lira 2026-11-18): o slug sai
 * do título e trocar o título derrubava o link do anúncio (404). Agora há
 * slug fixo opcional e os slugs antigos levam ao produto (301).
 */
class SlugDoProdutoFerraKoraTest extends TestCase
{
    use RefreshDatabase;

    private function produtoNoSite(array $site = []): Product
    {
        $pai = Product::factory()->create([
            'sku' => 'FER-BOMBA', 'slug' => 'bomba-de-ar-kz', 'name' => 'Bomba de Ar Portatil Compressor 4 em 1', 'variation' => null,
            'price' => 199.90, 'stock' => 5, 'is_active' => true, 'discount_percentage' => null, 'discount_amount' => null,
        ]);
        FerraKoraProduto::query()->create(['product_id' => $pai->id, 'ordem' => 1, 'ativo' => true, ...$site]);

        return $pai;
    }

    public function test_slug_fixo_mantem_o_endereco_quando_o_titulo_muda(): void
    {
        $pai = $this->produtoNoSite(['slug' => 'bomba-de-ar-portatil-compressor-4-em-1']);

        $pai->update(['name' => 'Pneu Calibrado em Minutos: Compressor 4 em 1 + PDF Grátis']);

        $produto = $this->getJson('/api/ferrakora/produtos')->assertOk()->json('products.0');
        $this->assertSame('Pneu Calibrado em Minutos: Compressor 4 em 1 + PDF Grátis', $produto['title']);
        $this->assertSame('bomba-de-ar-portatil-compressor-4-em-1', $produto['slug']);
        $this->getJson('/api/ferrakora/produtos/bomba-de-ar-portatil-compressor-4-em-1')
            ->assertOk()
            ->assertJsonPath('product.id', 'kz-'.$pai->id);
        $this->assertSame(0, OutroSiteSlugAntigo::count());
    }

    public function test_renomear_guarda_o_slug_antigo_e_ele_leva_ao_produto(): void
    {
        $pai = $this->produtoNoSite();
        $antigo = 'bomba-de-ar-portatil-compressor-4-em-1';
        $this->getJson('/api/ferrakora/produtos/'.$antigo)->assertOk();

        $pai->update(['name' => 'Pneu Calibrado em Minutos: Compressor 4 em 1']);
        $novo = 'pneu-calibrado-em-minutos-compressor-4-em-1';

        $this->assertDatabaseHas('outro_site_slugs_antigos', ['site' => 'ferrakora', 'slug' => $antigo, 'product_id' => $pai->id]);
        // A API acha pelo slug antigo e devolve o atual: o site troca a URL.
        $this->getJson('/api/ferrakora/produtos/'.$antigo)
            ->assertOk()
            ->assertJsonPath('product.id', 'kz-'.$pai->id)
            ->assertJsonPath('product.slug', $novo);
        // Link antigo aberto direto no site (Nginx -> aqui): 301 para o novo.
        $this->get('/api/ferrakora/produto-antigo/'.$antigo)
            ->assertStatus(301)
            ->assertHeader('Location', '/produto/'.$novo.'/');
        // Link do anúncio: a query (utm, fbclid) vai junto para o endereço novo.
        $this->get('/api/ferrakora/produto-antigo/'.$antigo.'?utm_source=meta&utm_campaign=fk%20bf&fbclid=abc')
            ->assertStatus(301)
            ->assertHeader('Location', '/produto/'.$novo.'/?utm_source=meta&utm_campaign=fk%20bf&fbclid=abc');
        // O atual e um slug que nunca existiu não redirecionam.
        $this->get('/api/ferrakora/produto-antigo/'.$novo)->assertNotFound();
        $this->get('/api/ferrakora/produto-antigo/nao-existe')->assertNotFound();
        $this->getJson('/api/ferrakora/produtos/nao-existe')->assertNotFound();
    }

    public function test_titulo_do_site_e_slug_fixo_tambem_guardam_o_anterior(): void
    {
        $pai = $this->produtoNoSite();
        $registro = FerraKoraProduto::query()->where('product_id', $pai->id)->firstOrFail();

        $registro->update(['titulo' => 'Compressor 4 em 1 Portátil']);
        $registro->update(['slug' => 'compressor-fixo']);

        $this->assertEqualsCanonicalizing(
            ['bomba-de-ar-portatil-compressor-4-em-1', 'compressor-4-em-1-portatil'],
            OutroSiteSlugAntigo::query()->where('product_id', $pai->id)->pluck('slug')->all(),
        );
        $this->get('/api/ferrakora/produto-antigo/bomba-de-ar-portatil-compressor-4-em-1')
            ->assertStatus(301)->assertHeader('Location', '/produto/compressor-fixo/');

        // Voltar a um slug que já foi antigo: ele deixa de ser "antigo".
        $registro->update(['slug' => null, 'titulo' => null]);
        $this->assertDatabaseMissing('outro_site_slugs_antigos', ['slug' => 'bomba-de-ar-portatil-compressor-4-em-1']);
        $this->get('/api/ferrakora/produto-antigo/compressor-fixo')
            ->assertStatus(301)->assertHeader('Location', '/produto/bomba-de-ar-portatil-compressor-4-em-1/');
    }

    public function test_produto_fora_do_site_nao_redireciona(): void
    {
        $pai = $this->produtoNoSite();
        $pai->update(['name' => 'Compressor Novo']);
        FerraKoraProduto::query()->where('product_id', $pai->id)->update(['ativo' => false]);

        $this->get('/api/ferrakora/produto-antigo/bomba-de-ar-portatil-compressor-4-em-1')->assertNotFound();
    }

    public function test_kit_que_saiu_do_site_com_destino_leva_ao_produto_do_lugar(): void
    {
        // Kits do Compressor 4 em 1 desfeitos (26/11/2026): o kit sai do FerraKora e da vitrine da
        // KazaKora com destino no compressor; o link antigo do kit abre o compressor.
        $compressor = $this->produtoNoSite(['slug' => 'bomba-de-ar-portatil-compressor-4-em-1']);
        $kit = Product::factory()->create(['sku' => 'FK-KIT', 'slug' => 'kit-macaco-kz', 'name' => 'Kit Macaco Elétrico', 'is_active' => true, 'stock' => 5]);
        FerraKoraProduto::query()->create(['product_id' => $kit->id, 'ordem' => 2, 'ativo' => false]);
        OutroSiteSlugAntigo::query()->create(['site' => 'ferrakora', 'slug' => 'kit-macaco-eletrico', 'product_id' => $kit->id]);

        // Sem destino: some do site (404), como qualquer produto desligado.
        $this->getJson('/api/ferrakora/produtos/kit-macaco-eletrico')->assertNotFound();
        $this->get('/api/ferrakora/produto-antigo/kit-macaco-eletrico')->assertNotFound();

        $kit->forceFill(['fora_da_vitrine_kz' => true, 'vitrine_kz_redireciona_para' => $compressor->id])->save();

        $this->getJson('/api/ferrakora/produtos/kit-macaco-eletrico')
            ->assertOk()
            ->assertJsonPath('product.id', 'kz-'.$compressor->id)
            ->assertJsonPath('product.slug', 'bomba-de-ar-portatil-compressor-4-em-1')
            ->assertJsonMissingPath('product.opcao_inicial');
        $this->get('/api/ferrakora/produto-antigo/kit-macaco-eletrico?utm_source=meta&opcao=9')
            ->assertStatus(301)
            ->assertHeader('Location', '/produto/bomba-de-ar-portatil-compressor-4-em-1/?utm_source=meta&opcao=9');
    }

    public function test_beauty_kora_usa_o_mesmo_slug_fixo_e_historico_por_site(): void
    {
        $pai = $this->produtoNoSite();
        BeautyKoraProduto::query()->create(['product_id' => $pai->id, 'ordem' => 1, 'ativo' => true, 'slug' => 'bomba-beauty']);

        $pai->update(['name' => 'Compressor Novo']);

        $this->getJson('/api/beautykora/produtos')->assertOk()->assertJsonPath('products.0.slug', 'bomba-beauty');
        $this->assertSame(['ferrakora'], OutroSiteSlugAntigo::query()->pluck('site')->all());
    }
}
