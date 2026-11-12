<?php

namespace Tests\Feature\Admin;

use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Catalog\Models\Product;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use App\Modules\Fiscal\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Frete incluído no preço visto (pedido do Lira, 2026-11-12): com o frete
 * embutido ligado, a visita guarda o preço total que a página mostrou e o
 * frete que estava dentro dele, na loja e no FerraKora. Mesma preparação de
 * PrecoComFreteFerraKoraTest.
 */
class VisitasFreteIncluidoTest extends TestCase
{
    use RefreshDatabase;

    private const NAVEGADOR = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148';

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

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/preco/')) {
                return Http::response(collect($request['parametrosProduto'])->map(fn ($p) => [
                    'coProduto' => $p['coProduto'], 'nuRequisicao' => $p['nuRequisicao'],
                    'pcFinal' => $p['coProduto'] === '03220' ? '9,94' : '15,45',
                ])->all());
            }

            return Http::response(collect($request['parametrosPrazo'])->map(fn ($p) => [
                'coProduto' => $p['coProduto'], 'nuRequisicao' => $p['nuRequisicao'], 'prazoEntrega' => 3,
            ])->all());
        });

        $produto = Product::factory()->create([
            'sku' => 'FER-PIP', 'slug' => 'pipoqueira', 'name' => 'Pipoqueira', 'price' => 65, 'stock' => 10, 'is_active' => true,
            'discount_percentage' => null, 'discount_amount' => null,
        ]);
        FerraKoraProduto::create(['product_id' => $produto->id, 'ordem' => 1, 'ativo' => true]);
    }

    public function test_aviso_do_ferrakora_grava_o_total_da_api_e_o_frete_dentro_dele(): void
    {
        $naApi = $this->getJson('/api/ferrakora/produtos/pipoqueira?simular_uf=SP')->assertOk()->json('product.variants.0.prices.0.amount');
        $this->app->forgetScopedInstances();

        $this->withHeaders(['Origin' => 'https://ferrakora.devlira.com.br', 'User-Agent' => self::NAVEGADOR])
            ->post('/api/ferrakora/visita?p=/produto/pipoqueira&simular_uf=SP')->assertNoContent();

        $visita = SiteVisit::sole();
        $this->assertSame($naApi / 100, $visita->preco_visto);
        $this->assertGreaterThan(0, $visita->frete_visto);
        // O que sobra tirando o frete é o preço do anúncio.
        $this->assertSame(65.0, round($visita->preco_visto - $visita->frete_visto, 2));
    }

    public function test_pagina_do_produto_da_loja_grava_o_total_e_o_frete_dentro_dele(): void
    {
        $this->withHeader('User-Agent', self::NAVEGADOR)
            ->get('https://kazakora.devlira.com.br/produtos/pipoqueira?simular_uf=SP')->assertOk();

        $visita = SiteVisit::sole();
        $this->assertGreaterThan(65.0, $visita->preco_visto);
        $this->assertGreaterThan(0, $visita->frete_visto);
        $this->assertSame(65.0, round($visita->preco_visto - $visita->frete_visto, 2));
    }
}
