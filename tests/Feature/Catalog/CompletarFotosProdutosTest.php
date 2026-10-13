<?php

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Services\CompletarFotosProduto;
use App\Services\Bling\BlingClient;
use App\Services\MercadoLivre\MercadoLivreClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * produtos:completar-fotos (2026-10-13): produto sem nenhuma foto ganha as
 * fotos do arquivo do fornecedor, do Bling (mesmo código) ou, se pedido, do
 * catálogo do ML com nome parecido. --dry-run não grava nada e rodar de novo
 * não duplica.
 */
class CompletarFotosProdutosTest extends TestCase
{
    use RefreshDatabase;

    private const JPEG = "\xFF\xD8\xFF\xE0";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Http::fake(['fotos.exemplo/*' => Http::response(self::JPEG.str_repeat('x', 2000))]);
    }

    private function arquivo(array $itens): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'fotos');
        file_put_contents($caminho, json_encode($itens));

        return $caminho;
    }

    private function semBling(): void
    {
        $this->mock(BlingClient::class, fn (MockInterface $m) => $m->shouldReceive('get')->andThrow(new RuntimeException('sem token')));
    }

    public function test_dry_run_nao_grava_e_arquivo_preenche_sem_duplicar(): void
    {
        $this->semBling();
        $sem = Product::factory()->create(['sku' => 'KIC-S079']);
        $com = Product::factory()->create(['sku' => 'KIC-1']);
        ProductImage::query()->create(['product_id' => $com->id, 'path' => 'products/x.jpg', 'position' => 0, 'is_primary' => true]);
        $arquivo = $this->arquivo([['sku' => 'KIC-S079', 'fotos' => ['https://fotos.exemplo/a.jpg', 'https://fotos.exemplo/b.jpg']]]);

        $this->artisan('produtos:completar-fotos', ['--prefixo' => 'KIC-', '--arquivo' => $arquivo, '--dry-run' => true])
            ->expectsOutputToContain('preencheria: 1')->assertSuccessful();
        $this->assertSame(0, $sem->images()->count());

        $this->artisan('produtos:completar-fotos', ['--prefixo' => 'KIC-', '--arquivo' => $arquivo])
            ->expectsOutputToContain('preenchido: 1')->assertSuccessful();
        $this->assertSame(2, $sem->images()->count());
        $this->assertTrue((bool) $sem->images()->orderBy('position')->first()->is_primary);

        $this->artisan('produtos:completar-fotos', ['--prefixo' => 'KIC-', '--arquivo' => $arquivo])
            ->expectsOutputToContain('nada a fazer')->assertSuccessful();
        $this->assertSame(2, $sem->images()->count());
        $this->assertSame(1, $com->images()->count());
    }

    public function test_busca_no_bling_pelo_codigo(): void
    {
        $produto = Product::factory()->create(['sku' => 'CAR-1']);
        $this->mock(BlingClient::class, function (MockInterface $m) {
            $m->shouldReceive('get')->with('produtos', ['codigo' => 'CAR-1'])->andReturn(['data' => [['id' => 99]]]);
            $m->shouldReceive('get')->with('produtos/99')->andReturn(['data' => ['midia' => ['imagens' => [
                'internas' => [['link' => 'https://fotos.exemplo/bling.jpg']], 'externas' => [], 'imagensURL' => [],
            ]]]]);
        });

        $this->artisan('produtos:completar-fotos')->expectsOutputToContain('preenchido: 1')->assertSuccessful();
        $this->assertSame(1, $produto->images()->count());
    }

    public function test_catalogo_do_ml_so_com_nome_parecido_e_so_quando_pedido(): void
    {
        $this->semBling();
        $parecido = Product::factory()->create(['sku' => 'KIC-S092', 'name' => 'Frigideira Antiaderente Japonesa Para Omelettes']);
        $diferente = Product::factory()->create(['sku' => 'KIC-S093', 'name' => 'Escada Telescópica de Alumínio 3,8 metros']);
        $this->mock(MercadoLivreClient::class, function (MockInterface $m) {
            $m->shouldReceive('get')->with('products/search', \Mockery::on(fn ($q) => str_contains($q['q'], 'Frigideira')))
                ->andReturn(['results' => [['id' => 'MLB1', 'name' => 'Frigideira Antiaderente Japonesa Para Omelettes Sortido', 'pictures' => [['url' => 'https://fotos.exemplo/ml.jpg']]]]]);
            $m->shouldReceive('get')->with('products/search', \Mockery::on(fn ($q) => str_contains($q['q'], 'Escada')))
                ->andReturn(['results' => [['id' => 'MLB2', 'name' => 'Banqueta Dobrável de Plástico', 'pictures' => [['url' => 'https://fotos.exemplo/x.jpg']]]]]);
        });

        $this->artisan('produtos:completar-fotos', ['--prefixo' => 'KIC-'])->expectsOutputToContain('sem_fonte: 2')->assertSuccessful();

        $this->artisan('produtos:completar-fotos', ['--prefixo' => 'KIC-', '--fonte' => 'ml-catalogo'])
            ->expectsOutputToContain('preenchido: 1')->assertSuccessful();
        $this->assertSame(1, $parecido->images()->count());
        $this->assertSame(0, $diferente->images()->count());
    }

    public function test_pagina_de_erro_nao_vira_foto(): void
    {
        Http::fake(['bloqueio.exemplo/*' => Http::response('<html>'.str_repeat('x', 2000).'</html>')]);

        $this->assertNull(CompletarFotosProduto::baixar('https://bloqueio.exemplo/a.jpg'));
        $this->assertSame(1.0, CompletarFotosProduto::semelhanca('Câmera de Ré 180 Graus', 'Camera Re Olho de Peixe 180 Graus Universal'));
    }
}
