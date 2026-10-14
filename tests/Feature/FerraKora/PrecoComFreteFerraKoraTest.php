<?php

namespace Tests\Feature\FerraKora;

use App\Modules\Catalog\Models\Product;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use App\Modules\Fiscal\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Preço do FerraKora com o frete embutido da região do visitante (pedido
 * 2026-10-13): o mesmo final_price da página do produto no KazaKora. O
 * Beauty Kora (2026-10-15) está em PrecoRegiaoBeautyKoraTest.
 */
class PrecoComFreteFerraKoraTest extends TestCase
{
    use RefreshDatabase;

    private Product $produto;

    protected function setUp(): void
    {
        parent::setUp();
        config(['frete_embutido.ativo' => true, 'app.url' => 'https://kazakora.devlira.com.br']);

        Company::create([
            'razao_social' => 'Kazakora Comércio Ltda', 'nome_fantasia' => 'KazaKora', 'cnpj' => '65604590000107',
            'regime_tributario' => Company::REGIME_SIMPLES_NACIONAL, 'phone' => '(11) 91234-5678', 'email' => 'contato@kazakora.com',
            'zip' => '03187-040', 'street' => 'Rua da Loja', 'number' => '100', 'neighborhood' => 'Mooca', 'city' => 'São Paulo', 'state' => 'SP',
        ]);
        config([
            'services.correios.numero_usuario' => '123',
            'services.correios.codigo_acesso_contrato' => 'token-contrato',
            'services.correios.preco_base_url' => 'https://correios.test/preco',
            'services.correios.prazo_base_url' => 'https://correios.test/prazo',
        ]);

        // São Paulo: SEDEX R$ 9,94 (mais barato que o PAC). Resto: PAC R$ 20.
        Http::fake(function (Request $request) {
            $sp = fn ($p) => str_starts_with($p['cepDestino'], '0');
            if (str_contains($request->url(), '/preco/')) {
                return Http::response(collect($request['parametrosProduto'])->map(fn ($p) => [
                    'coProduto' => $p['coProduto'], 'nuRequisicao' => $p['nuRequisicao'],
                    'pcFinal' => $p['coProduto'] === '03220' ? ($sp($p) ? '9,94' : '30,00') : ($sp($p) ? '15,45' : '20,00'),
                ])->all());
            }

            return Http::response(collect($request['parametrosPrazo'])->map(fn ($p) => [
                'coProduto' => $p['coProduto'], 'nuRequisicao' => $p['nuRequisicao'], 'prazoEntrega' => 3,
            ])->all());
        });

        $this->produto = Product::factory()->create([
            'sku' => 'FER-PIP', 'slug' => 'pipoqueira', 'name' => 'Pipoqueira', 'price' => 65, 'stock' => 10, 'is_active' => true,
            'discount_percentage' => null, 'discount_amount' => null,
        ]);
        FerraKoraProduto::create(['product_id' => $this->produto->id, 'ordem' => 1, 'ativo' => true]);
    }

    /** FreteEmbutido é scoped (um por requisição); no teste as requisições dividem o mesmo app. */
    private function novaRequisicao(): void
    {
        $this->app->forgetScopedInstances();
    }

    private function preco(array $produto): array
    {
        return $produto['variants'][0]['prices'][0];
    }

    public function test_preco_soma_o_frete_da_uf_simulada_e_bate_com_a_loja(): void
    {
        $sp = $this->getJson('/api/ferrakora/produtos/pipoqueira?simular_uf=SP')->assertOk()->json('product');
        $this->assertSame(7494, $this->preco($sp)['amount']);
        $this->assertNull($this->preco($sp)['sale_amount']);
        $this->assertTrue($sp['frete_gratis']);
        $this->assertEquals(5, $sp['desconto_pix']);

        $this->novaRequisicao();
        $am = $this->getJson('/api/ferrakora/produtos?simular_uf=AM')->assertOk()->json('products.0');
        $this->assertSame(8500, $this->preco($am)['amount']);

        $this->novaRequisicao();
        // Mesmo número que a página do produto no KazaKora mostra para a UF.
        $this->get('/produtos/pipoqueira?simular_uf=AM')->assertOk()
            ->assertInertia(fn ($page) => $page->where('product.final_price', 85));
    }

    public function test_usa_a_regiao_da_cloudflare_e_sem_regiao_o_valor_de_seguranca(): void
    {
        $sp = $this->getJson('/api/ferrakora/produtos/pipoqueira', ['CF-IPCountry' => 'BR', 'CF-Region-Code' => 'SP'])->assertOk();
        $this->assertSame(7494, $this->preco($sp->json('product'))['amount']);
        $this->assertStringContainsString('no-store', (string) $sp->headers->get('Cache-Control'));

        $this->novaRequisicao();
        $semRegiao = $this->getJson('/api/ferrakora/produtos/pipoqueira')->assertOk()->json('product');
        $this->assertSame(9500, $this->preco($semRegiao)['amount']);
    }

    public function test_com_desconto_o_preco_de_tambem_leva_o_frete(): void
    {
        $this->produto->update(['discount_percentage' => 10]);

        $preco = $this->preco($this->getJson('/api/ferrakora/produtos/pipoqueira?simular_uf=AM')->assertOk()->json('product'));

        $this->assertSame(8500, $preco['amount']);
        $this->assertSame(7850, $preco['sale_amount']);
    }

    public function test_desligado_no_config_fica_o_preco_de_cadastro(): void
    {
        config(['frete_embutido.ativo' => false]);

        $produto = $this->getJson('/api/ferrakora/produtos/pipoqueira?simular_uf=AM')->assertOk()->json('product');

        $this->assertSame(6500, $this->preco($produto)['amount']);
        $this->assertFalse($produto['frete_gratis']);
    }
}
