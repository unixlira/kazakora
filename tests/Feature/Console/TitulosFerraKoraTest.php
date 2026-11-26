<?php

namespace Tests\Feature\Console;

use App\Modules\Catalog\Models\Product;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** ferrakora:titulos (pedido do Lira 26/11/2026): título sem "+ PDF" e link antigo com 301. */
class TitulosFerraKoraTest extends TestCase
{
    use RefreshDatabase;

    private function csv(string $conteudo): string
    {
        $arquivo = tempnam(sys_get_temp_dir(), 'titulos-fk').'.csv';
        file_put_contents($arquivo, $conteudo);

        return $arquivo;
    }

    private function noSite(array $produto, array $site = []): Product
    {
        $pai = Product::factory()->create([
            'variation' => null, 'price' => 99.90, 'stock' => 5, 'is_active' => true,
            'discount_percentage' => null, 'discount_amount' => null, ...$produto,
        ]);
        FerraKoraProduto::query()->create(['product_id' => $pai->id, 'ordem' => 1, 'ativo' => true, ...$site]);

        return $pai;
    }

    public function test_seco_so_mostra(): void
    {
        $pai = $this->noSite(['name' => 'Macaco Garrafa + PDF Grátis', 'so_ferrakora' => true]);

        $this->artisan('ferrakora:titulos', ['arquivo' => $this->csv("id;titulo_novo\n{$pai->id};Levante o Carro: Macaco Hidráulico 4 Toneladas\n"), '--seco' => true])
            ->assertSuccessful();

        $this->assertSame('Macaco Garrafa + PDF Grátis', $pai->fresh()->name);
        $this->assertNull(FerraKoraProduto::query()->first()->titulo);
    }

    public function test_aplica_com_301_mantendo_a_query_e_desfaz(): void
    {
        Storage::fake('local');

        $soFk = $this->noSite([
            'name' => 'Levante o Carro: Macaco 4 Toneladas + PDF Grátis', 'so_ferrakora' => true,
            'nome_interno' => null, 'termos_busca' => null, 'seo_title' => 'Levante o Carro: Macaco 4 Toneladas + PDF Grátis',
        ]);
        $daLoja = $this->noSite(
            ['name' => 'Título Persuasivo da KazaKora: Trena 10m', 'so_ferrakora' => false],
            ['titulo' => 'Trena 10m + PDF Ganhe Dinheiro com Ferramentas'],
        );
        $comPdf = $this->noSite(['name' => 'Alicate + PDF Grátis', 'so_ferrakora' => true]);

        $antigoSoFk = 'levante-o-carro-macaco-4-toneladas-pdf-gratis';
        $antigoLoja = 'trena-10m-pdf-ganhe-dinheiro-com-ferramentas';
        $this->getJson('/api/ferrakora/produtos/'.$antigoSoFk)->assertOk();

        $novoSoFk = 'Levante o Carro com Segurança: Macaco Hidráulico Tipo Garrafa 4 Toneladas';
        $novoLoja = 'Suja na Obra, Limpa Fácil: Trena 10m Fita Métrica em Aço Inox';
        $arquivo = $this->csv("\xEF\xBB\xBFid;titulo_novo\n{$soFk->id};{$novoSoFk}\n{$daLoja->id};{$novoLoja}\n{$comPdf->id};Alicate Bom + PDF Grátis\n");

        $this->artisan('ferrakora:titulos', ['arquivo' => $arquivo])->assertSuccessful();

        // Só da FerraKora: nome, SEO e busca acompanham; NF-e sem PDF.
        $soFk->refresh();
        $this->assertSame($novoSoFk, $soFk->name);
        $this->assertSame($novoSoFk, $soFk->seo_title);
        $this->assertSame('Levante o Carro: Macaco 4 Toneladas', $soFk->nome_interno);
        $this->assertSame('Levante o Carro: Macaco 4 Toneladas + PDF Grátis', $soFk->termos_busca);

        // Da loja: o nome da KazaKora não muda, só o título no site.
        $this->assertSame('Título Persuasivo da KazaKora: Trena 10m', $daLoja->fresh()->name);
        $this->assertSame($novoLoja, FerraKoraProduto::query()->where('product_id', $daLoja->id)->value('titulo'));

        // Título que ainda promete PDF é pulado.
        $this->assertSame('Alicate + PDF Grátis', $comPdf->fresh()->name);

        $novoSlug = 'suja-na-obra-limpa-facil-trena-10m-fita-metrica-em-aco-inox';
        $this->getJson('/api/ferrakora/produtos/'.$novoSlug)->assertOk()->assertJsonPath('product.title', $novoLoja);
        $this->get('/api/ferrakora/produto-antigo/'.$antigoLoja.'?utm_source=fb&fbclid=x1')
            ->assertStatus(301)
            ->assertHeader('Location', '/produto/'.$novoSlug.'/?utm_source=fb&fbclid=x1');
        $this->get('/api/ferrakora/produto-antigo/'.$antigoSoFk)
            ->assertStatus(301)
            ->assertHeader('Location', '/produto/levante-o-carro-com-seguranca-macaco-hidraulico-tipo-garrafa-4-toneladas/');

        // Desfazer: tudo volta e o endereço novo passa a levar ao antigo.
        $backup = Storage::disk('local')->files('titulos-ferrakora')[0];
        $this->artisan('ferrakora:titulos', ['--desfazer' => $backup])->assertSuccessful();

        $soFk->refresh();
        $this->assertSame('Levante o Carro: Macaco 4 Toneladas + PDF Grátis', $soFk->name);
        $this->assertNull($soFk->nome_interno);
        $this->assertNull($soFk->termos_busca);
        $this->assertSame('Trena 10m + PDF Ganhe Dinheiro com Ferramentas', FerraKoraProduto::query()->where('product_id', $daLoja->id)->value('titulo'));
        $this->getJson('/api/ferrakora/produtos/'.$antigoLoja)->assertOk()->assertJsonPath('product.slug', $antigoLoja);
        $this->get('/api/ferrakora/produto-antigo/'.$novoSlug)->assertStatus(301)->assertHeader('Location', '/produto/'.$antigoLoja.'/');
    }
}
