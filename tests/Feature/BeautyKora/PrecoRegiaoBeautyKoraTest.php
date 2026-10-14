<?php

namespace Tests\Feature\BeautyKora;

use App\Modules\BeautyKora\Models\BeautyKoraProduto;
use App\Modules\BeautyKora\Services\CheckoutBeautyKora;
use App\Modules\Catalog\Models\Product;
use App\Modules\Fiscal\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Beauty Kora pela região do visitante (pedido 2026-10-15): preço com o
 * frete embutido da região (IP ou CEP), +5% sobre o anúncio no produto
 * clonado e GET /api/beautykora/regiao com o frete grátis e a entrega FULL
 * (raio) para o card. O checkout cobra o mesmo que o card mostrou.
 */
class PrecoRegiaoBeautyKoraTest extends TestCase
{
    use RefreshDatabase;

    private Product $produto;

    private BeautyKoraProduto $registro;

    protected function setUp(): void
    {
        parent::setUp();
        config(['frete_embutido.ativo' => true, 'app.url' => 'https://kazakora.devlira.com.br']);
        Carbon::setTestNow(Carbon::parse('2026-10-15 10:00', 'America/Sao_Paulo'));

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
            'sku' => 'BEL-BLU', 'slug' => 'blush', 'name' => 'Blush', 'price' => 20, 'stock' => 10, 'is_active' => true,
            'discount_percentage' => null, 'discount_amount' => null,
        ]);
        $this->registro = BeautyKoraProduto::create(['product_id' => $this->produto->id, 'ordem' => 1, 'ativo' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** FreteEmbutido é scoped (um por requisição); no teste as requisições dividem o mesmo app. */
    private function novaRequisicao(): void
    {
        $this->app->forgetScopedInstances();
    }

    private function preco(string $url, array $cabecalhos = []): array
    {
        return $this->getJson($url, $cabecalhos)->assertOk()->json('product.variants.0.prices.0');
    }

    public function test_clonado_por_padrao_leva_5_por_cento_sobre_o_anuncio_e_o_frete_da_regiao(): void
    {
        $this->assertTrue($this->registro->fresh()->clonado);

        // AM: 20 × 1,05 + 20 de frete = 41,00. SP: 21 + 9,94 = 30,94.
        $this->assertSame(4100, $this->preco('/api/beautykora/produtos/blush?simular_uf=AM')['amount']);
        $this->novaRequisicao();
        $resposta = $this->getJson('/api/beautykora/produtos', ['CF-IPCountry' => 'BR', 'CF-Region-Code' => 'SP'])->assertOk();
        $this->assertSame(3094, $resposta->json('products.0.variants.0.prices.0.amount'));
        $this->assertTrue($resposta->json('products.0.frete_gratis'));
        $this->assertTrue($resposta->json('products.0.clonado'));
        $this->assertStringContainsString('no-store', (string) $resposta->headers->get('Cache-Control'));
    }

    public function test_nao_clonado_fica_com_o_preco_do_anuncio_mais_o_frete(): void
    {
        $this->postJson('/api/beautykora/produtos', ['produtos' => [['sku' => 'BEL-BLU', 'clonado' => false]]], [
            'Authorization' => 'Bearer '.tap('tk', fn ($t) => config(['services.beautykora.catalogo_token' => $t])),
        ])->assertOk()->assertJsonPath('gravados.0.clonado', false);

        $this->novaRequisicao();
        $this->assertSame(4000, $this->preco('/api/beautykora/produtos/blush?simular_uf=AM')['amount']);
    }

    public function test_com_desconto_o_riscado_e_o_de_venda_levam_o_acrescimo(): void
    {
        $this->produto->update(['discount_percentage' => 10]);

        $preco = $this->preco('/api/beautykora/produtos/blush?simular_uf=AM');

        // Cheio 20 × 1,05 + 20 = 41; com 10%: 18 × 1,05 + 20 = 38,90.
        $this->assertSame(4100, $preco['amount']);
        $this->assertSame(3890, $preco['sale_amount']);
    }

    public function test_regiao_pelo_ip_na_grande_sp_tem_full(): void
    {
        $this->getJson('/api/beautykora/regiao', ['CF-IPCountry' => 'BR', 'CF-Region-Code' => 'SP', 'CF-IPCity' => 'Osasco'])
            ->assertOk()
            ->assertJsonPath('frete_gratis', true)
            ->assertJsonPath('origem', 'ip')
            ->assertJsonPath('uf', 'SP')
            ->assertJsonPath('full.local', 'Osasco')
            ->assertJsonPath('full.mensagem', 'Receba hoje até as 21h')
            ->assertJsonPath('turbo', false);

        $this->novaRequisicao();
        $this->getJson('/api/beautykora/regiao?simular_uf=SP&simular_cidade=sao paulo')->assertOk()->assertJsonPath('full.local', 'São Paulo');
    }

    public function test_regiao_fora_da_grande_sp_tem_frete_gratis_sem_full(): void
    {
        $this->getJson('/api/beautykora/regiao', ['CF-IPCountry' => 'BR', 'CF-Region-Code' => 'SP', 'CF-IPCity' => 'Campinas'])
            ->assertOk()->assertJsonPath('frete_gratis', true)->assertJsonPath('full', null)->assertJsonPath('turbo', true);

        $this->novaRequisicao();
        $this->getJson('/api/beautykora/regiao', ['CF-IPCountry' => 'BR', 'CF-Region-Code' => 'CE', 'CF-IPCity' => 'Fortaleza'])
            ->assertOk()->assertJsonPath('uf', 'CE')->assertJsonPath('full', null);

        $this->novaRequisicao();
        $this->getJson('/api/beautykora/regiao')->assertOk()->assertJsonPath('origem', null)->assertJsonPath('full', null);
    }

    public function test_cep_digitado_vale_acima_do_ip(): void
    {
        $this->getJson('/api/beautykora/regiao?cep=06010-000', ['CF-IPCountry' => 'BR', 'CF-Region-Code' => 'CE'])
            ->assertOk()->assertJsonPath('origem', 'cep')->assertJsonPath('full.local', 'Osasco');

        $this->novaRequisicao();
        $this->getJson('/api/beautykora/regiao?cep=69900100')->assertOk()->assertJsonPath('full', null);
    }

    public function test_checkout_cobra_o_mesmo_valor_do_card(): void
    {
        $card = $this->preco('/api/beautykora/produtos/blush?cep=69900100')['amount'];

        $this->novaRequisicao();
        $itens = app(CheckoutBeautyKora::class)->itens([['variante' => 'kzv-'.$this->produto->id, 'quantidade' => 2]], '69900100');

        $this->assertSame(4100, $card);
        $this->assertEquals(41.0, $itens[0]['preco_unitario']);
        $this->assertEquals(82.0, $itens[0]['subtotal']);
    }
}
