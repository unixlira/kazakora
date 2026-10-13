<?php

namespace Tests\Feature\FerraKora;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Clonagem da Kikool para o KazaKora + FerraKora (pedido 2026-10-13): preço
 * +35% sobre o custo, estoque 10, dados completados pelo Gemini e nada
 * duplicado.
 */
class ClonarKikoolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config([
            'services.gemini.api_key' => 'chave-de-teste',
            'services.gemini.chat_model' => 'gemini-teste',
            'services.gemini.fallback_models' => [],
            'services.gemini.retry_delay_ms' => 0,
        ]);
    }

    private function arquivo(array $itens): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'kikool');
        file_put_contents($caminho, json_encode($itens));

        return $caminho;
    }

    private function respostaGemini(): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => json_encode([
            'nome' => 'Jogo de Soquete Catraca 12 Peças com Extensão Kicool',
            'descricao' => "Jogo de soquetes com catraca.\n- 12 peças\n- Extensão",
            'categoria_loja' => 'Ferramentas e Automotivo',
            'categoria_ferrakora' => 'Ferramentas Manuais',
            'ncm' => '8204.20.00',
            'peso_kg' => 0.8,
            'altura_cm' => 6,
            'largura_cm' => 15,
            'profundidade_cm' => 25,
            'formato_embalagem' => 'caixa',
        ])]]]]], 'usageMetadata' => []];
    }

    public function test_clona_com_markup_estoque_fiscal_foto_e_ferrakora(): void
    {
        $categoria = Category::query()->create(['name' => 'Ferramentas e Automotivo', 'slug' => 'ferramentas']);
        Http::fake([
            'down-br.img.susercontent.com/*' => Http::response(str_repeat("\xFF\xD8\xFF", 600)),
            'generativelanguage.googleapis.com/*' => Http::response($this->respostaGemini()),
        ]);

        $this->artisan('kikool:clonar', ['arquivo' => $this->arquivo([[
            'sku' => 'KIC-22693207666', 'nome' => 'Jogo De Soquete Sextavado Catraca 12 Peças com Extensão',
            'custo' => 28.99, 'fotos' => ['https://down-br.img.susercontent.com/file/abc'],
        ]])])->assertSuccessful();

        $produto = Product::query()->where('sku', 'KIC-22693207666')->firstOrFail();
        $this->assertSame('Jogo de Soquete Catraca 12 Peças com Extensão', $produto->name);
        $this->assertEquals(39.14, (float) $produto->price);
        $this->assertEquals(28.99, (float) $produto->cost_price);
        $this->assertSame(10, $produto->stock);
        $this->assertTrue($produto->is_active);
        $this->assertSame($categoria->id, $produto->category_id);
        $this->assertCount(1, $produto->images);
        $this->assertSame('82042000', $produto->fiscalData->ncm);
        $this->assertSame('caixa', $produto->fiscalData->formato_embalagem);

        $ferra = FerraKoraProduto::query()->where('product_id', $produto->id)->firstOrFail();
        $this->assertSame('Ferramentas Manuais', $ferra->categoria);
        $this->assertTrue($ferra->ativo);
    }

    public function test_nao_clona_o_que_ja_existe_nem_quando_o_gemini_falha(): void
    {
        Product::factory()->create(['sku' => 'OUTRO-1', 'name' => 'Webcam Full HD USB']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'cota']], 429)]);

        $this->artisan('kikool:clonar', ['arquivo' => $this->arquivo([
            ['sku' => 'KIC-1', 'nome' => 'Webcam Full HD USB', 'custo' => 30],
            ['sku' => 'KIC-2', 'nome' => 'Produto Novo', 'custo' => 30],
        ])])->assertSuccessful();

        $this->assertSame(1, Product::query()->count());
        $this->assertSame(0, FerraKoraProduto::query()->count());
    }
}
