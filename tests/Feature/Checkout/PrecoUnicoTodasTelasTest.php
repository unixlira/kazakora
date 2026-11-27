<?php

namespace Tests\Feature\Checkout;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Coupon;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use App\Modules\Fiscal\Models\Company;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Support\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Preço único em todas as telas (incidente 26/11/2026, Lira: "na listagem
 * aparece um preço, ao clicar aparece outro, no checkout aparece outro").
 *
 * O mesmo produto tem que mostrar o mesmo preço (de/por, cupom, Pix) na
 * vitrine, na busca, na página, no carrinho e no checkout, na KazaKora e no
 * FerraKora. Só a linha de frete do checkout pode somar algo, quando o CEP de
 * entrega é de região mais cara que a da navegação.
 *
 * Causas cobertas: o card carregava o produto sem sku e sem cost_price
 * (Product::scopeForCard), então o kit de preço fixo pelo SKU ganhava frete só
 * no card (R$ 317,42 x R$ 297,90) e o teto do frete não entrava (R$ 744,01 x
 * R$ 743,89); e o checkout do FerraKora trocava o frete do item pelo do CEP
 * (R$ 236,52 no card, R$ 244,15 no checkout para o CE).
 *
 * Correios de mentira: São Paulo SEDEX R$ 9,94 (PAC R$ 15,45); resto do Brasil
 * PAC R$ 40 e SEDEX R$ 50 (acima do teto de R$ 30, para o teto valer).
 */
class PrecoUnicoTodasTelasTest extends TestCase
{
    use RefreshDatabase;

    private const SP = ['CF-IPCountry' => 'BR', 'CF-Region-Code' => 'SP'];

    private const CE = ['CF-IPCountry' => 'BR', 'CF-Region-Code' => 'CE'];

    private const CEP_SP = '01310100';

    private const CEP_CE = '60025000';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow(Carbon::parse('2026-11-26 10:00', 'America/Sao_Paulo'));
        config([
            'app.url' => 'https://kazakora.com',
            'frete_embutido.ativo' => true,
            'frete_embutido.fixo_na_navegacao' => true,
            'frete_embutido.teto_embutido' => 30,
            'frete_embutido.transportadoras_melhor_envio' => [],
            'frete_embutido.skus_preco_fixo' => ['FK-KIT-COMPRESSOR-REPARO-CATRACA', 'FK-KIT-MACACO-ELET-5T', '344-BOMBAAR-4EM1'],
            'entrega_expressa.motoboy.valor_sp' => 0.0,
            'loja.cupom_faixa' => 'BLACKFRIDAY',
            'loja.blackfriday_ate' => '2026-11-30 23:59:59',
        ]);
        PaymentGateway::setActive(PaymentGateway::MERCADOPAGO);
        ShippingMethod::factory()->create(['price' => 0, 'is_active' => true]);
        $this->comCorreios();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function comCorreios(): void
    {
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
            if (str_contains($request->url(), 'viacep.com.br')) {
                return Http::response([]);
            }
            if (! str_contains($request->url(), 'correios.test')) {
                return null;
            }
            $sp = fn ($p) => str_starts_with($p['cepDestino'], '0');
            if (str_contains($request->url(), '/preco/')) {
                return Http::response(collect($request['parametrosProduto'])->map(fn ($p) => [
                    'coProduto' => $p['coProduto'], 'nuRequisicao' => $p['nuRequisicao'],
                    'pcFinal' => $p['coProduto'] === '03220' ? ($sp($p) ? '9,94' : '50,00') : ($sp($p) ? '15,45' : '40,00'),
                ])->all());
            }

            return Http::response(collect($request['parametrosPrazo'])->map(fn ($p) => [
                'coProduto' => $p['coProduto'], 'nuRequisicao' => $p['nuRequisicao'],
                'prazoEntrega' => $p['coProduto'] === '03220' ? 3 : 8,
            ])->all());
        });
    }

    /**
     * Os 4 casos do incidente (623, 508, 413, 194) e 3 produtos comuns.
     *
     * @return array<string, array{0: array<string, mixed>, 1: ?float}>  atributos e o preço fixo esperado (null = com frete)
     */
    public static function produtos(): array
    {
        return [
            'kit estrada segura (623, sku fixo)' => [['sku' => 'FK-KIT-COMPRESSOR-REPARO-CATRACA', 'price' => 629.00, 'discount_amount' => 331.10, 'cost_price' => 180.00, 'checkout_discount_percentage' => 2], 297.90],
            'kit macaco elétrico (508, sku fixo)' => [['sku' => 'FK-KIT-MACACO-ELET-5T', 'price' => 1299.00, 'discount_amount' => 569.00, 'cost_price' => 400.00, 'checkout_discount_percentage' => 2], 730.00],
            // Opções desfeitas (Lira 26/11/2026): "de R$ 435,52 por R$ 297,00" fixo em qualquer CEP.
            'compressor 4 em 1 (413, sku fixo)' => [['sku' => '344-BOMBAAR-4EM1', 'price' => 435.52, 'discount_amount' => 138.52, 'cost_price' => 115.00, 'checkout_discount_percentage' => 2], 297.00],
            'macaco elétrico (194, com custo e teto)' => [['sku' => 'MACACO-194', 'price' => 911.34, 'discount_amount' => 197.45, 'cost_price' => 350.00, 'checkout_discount_percentage' => 2], null],
            'travesseiro (sem custo)' => [['sku' => 'TRAV-1', 'price' => 206.98, 'cost_price' => null, 'checkout_discount_percentage' => 2], null],
            'trena barata (piso de margem)' => [['sku' => 'TRENA-1', 'price' => 49.90, 'cost_price' => 25.00, 'checkout_discount_percentage' => 2], null],
            'parafusadeira (desconto % e Pix em R$)' => [['sku' => 'PARAF-1', 'price' => 300.00, 'discount_percentage' => 10, 'cost_price' => 120.00, 'checkout_discount_percentage' => null, 'checkout_discount_amount' => 5], null],
        ];
    }

    private function criar(array $atributos): Product
    {
        $produto = Product::factory()->create(array_merge([
            'stock' => 10, 'is_active' => true, 'so_ferrakora' => false,
            'discount_percentage' => null, 'discount_amount' => null, 'checkout_discount_amount' => null,
        ], $atributos, ['name' => 'Produto '.$atributos['sku']]));
        FerraKoraProduto::create(['product_id' => $produto->id, 'categoria' => 'Ferramentas', 'ordem' => 1, 'ativo' => true]);

        return $produto;
    }

    private function novaRequisicao(): void
    {
        $this->app->forgetScopedInstances();
    }

    /** Campos de preço do produto num payload Inertia (card da vitrine ou página). */
    private static function precos(array $p): array
    {
        return [
            'final' => round((float) $p['final_price'], 2),
            'de' => isset($p['preco_de']) ? round((float) $p['preco_de'], 2) : null,
            'cupom' => $p['preco_cupom'] ?? null,
        ];
    }

    /**
     * Percorre as telas da KazaKora navegando de uma região e devolve o que cada
     * uma mostrou para 1 unidade.
     *
     * @return array<string, mixed>
     */
    private function telasDaKazaKora(Product $produto, array $regiao, ?string $cepEntrega): array
    {
        $telas = [];

        $this->novaRequisicao();
        $home = $this->get('/?todos=1', $regiao)->assertOk();
        $card = collect($home->viewData('page')['props']['products']['data'])->firstWhere('id', $produto->id);
        $this->assertNotNull($card, 'O produto tem que aparecer na vitrine.');
        $telas['vitrine'] = self::precos($card);

        $this->novaRequisicao();
        $busca = $this->getJson('/busca/sugestoes?q='.urlencode($produto->name), $regiao)->assertOk();
        $sugestao = collect($busca->json('produtos'))->firstWhere('slug', $produto->slug);
        $telas['busca'] = round((float) $sugestao['preco_sem_cupom'], 2);
        $telas['busca_grande'] = round((float) $sugestao['preco'], 2);

        $this->novaRequisicao();
        $pagina = $this->get("/produtos/{$produto->slug}", $regiao)->assertOk();
        $telas['pagina'] = self::precos($pagina->viewData('page')['props']['product']);

        $this->novaRequisicao();
        $this->post('/carrinho', ['product_id' => $produto->id, 'quantity' => 1], $regiao);
        $this->novaRequisicao();
        $carrinho = $this->get('/carrinho', $regiao)->assertOk();
        $telas['carrinho'] = round((float) $carrinho->viewData('page')['props']['items'][0]['subtotal'], 2);

        $this->novaRequisicao();
        $checkout = $this->get('/finalizacao', $regiao)->assertOk();
        $props = $checkout->viewData('page')['props'];
        $telas['checkout'] = round((float) $props['subtotal'], 2);
        $telas['checkout_cupom'] = round((float) ($props['discountAmount'] ?? 0), 2);

        if ($cepEntrega) {
            $this->novaRequisicao();
            $entrega = $this->getJson('/finalizacao/entrega-gratis?cep='.$cepEntrega, $regiao)->assertOk();
            $telas['checkout_com_cep'] = round((float) $entrega->json('subtotal'), 2);
            $telas['frete_a_mais'] = round((float) $entrega->json('diferenca_frete'), 2);
        }

        return $telas;
    }

    private function confereKazaKora(array $telas, ?float $fixo, bool $comCupom): void
    {
        $preco = $telas['pagina']['final'];
        if ($fixo !== null) {
            $this->assertEqualsWithDelta($fixo, $preco, 0.001, 'Kit de preço fixo pelo SKU: o preço aprovado em qualquer região.');
        }

        $this->assertSame($telas['pagina'], $telas['vitrine'], 'Vitrine e página com o mesmo de/por/cupom/Pix.');
        $this->assertEqualsWithDelta($preco, $telas['busca'], 0.001, 'Busca com o preço da página.');
        $this->assertEqualsWithDelta((float) ($telas['pagina']['cupom']['por'] ?? $preco), $telas['busca_grande'], 0.001, 'Busca com o número grande do card.');
        $this->assertEqualsWithDelta($preco, $telas['carrinho'], 0.001, 'Carrinho com o preço da página.');
        $this->assertEqualsWithDelta($preco, $telas['checkout'], 0.001, 'Checkout com o preço da página.');
        if (isset($telas['checkout_com_cep'])) {
            $this->assertEqualsWithDelta($preco, $telas['checkout_com_cep'], 0.001, 'O CEP de entrega não muda o preço do item.');
            if ($fixo !== null) {
                $this->assertEqualsWithDelta(0.0, $telas['frete_a_mais'], 0.001, 'Kit de preço fixo: a loja paga o frete.');
            }
        }

        if ($comCupom) {
            $this->assertNotNull($telas['pagina']['cupom'], 'Black Friday no ar: a página mostra o preço com o cupom.');
            $this->assertEqualsWithDelta((float) $telas['pagina']['cupom']['cupom'], $telas['checkout_cupom'], 0.001, 'O cupom do checkout é o que a página e o card prometem.');
            $this->assertEqualsWithDelta($preco, (float) $telas['pagina']['cupom']['de'], 0.001);
        } else {
            $this->assertNull($telas['pagina']['cupom']);
            $this->assertEqualsWithDelta(0.0, $telas['checkout_cupom'], 0.001);
        }
    }

    #[DataProvider('produtos')]
    public function test_kazakora_mesmo_preco_em_todas_as_telas_navegando_de_sp_sem_cep(array $atributos, ?float $fixo): void
    {
        $produto = $this->criar($atributos);

        $this->confereKazaKora($this->telasDaKazaKora($produto, self::SP, null), $fixo, true);
    }

    #[DataProvider('produtos')]
    public function test_kazakora_mesmo_preco_navegando_do_nordeste_com_cep_de_sp(array $atributos, ?float $fixo): void
    {
        $produto = $this->criar($atributos);

        $this->confereKazaKora($this->telasDaKazaKora($produto, self::CE, self::CEP_SP), $fixo, true);
    }

    #[DataProvider('produtos')]
    public function test_kazakora_mesmo_preco_navegando_de_sp_com_cep_do_nordeste_e_sem_cupom(array $atributos, ?float $fixo): void
    {
        config(['loja.blackfriday_ate' => '2026-11-20 23:59:59']);
        Coupon::query()->where('code', 'BLACKFRIDAY')->update(['is_active' => false]);
        $produto = $this->criar($atributos);

        $this->confereKazaKora($this->telasDaKazaKora($produto, self::SP, self::CEP_CE), $fixo, false);
    }

    /** Preço de venda de 1 unidade no formato da API do FerraKora (centavos). */
    private static function vendaFk(array $produto): int
    {
        $preco = $produto['variants'][0]['prices'][0];

        return (int) ($preco['sale_amount'] ?? $preco['amount']);
    }

    #[DataProvider('produtos')]
    public function test_ferrakora_mesmo_preco_na_lista_na_pagina_e_no_checkout_com_cep_de_qualquer_regiao(array $atributos, ?float $fixo): void
    {
        // Em produção o BLACKFRIDAY vale também na FerraKora (liberado em 10/11/2026).
        Coupon::query()->where('code', 'BLACKFRIDAY')->update(['so_kazakora' => false]);
        $produto = $this->criar($atributos);
        $id = 'kz-'.$produto->id;

        foreach (['SP' => self::SP, 'CE' => self::CE] as $uf => $regiao) {
            $this->novaRequisicao();
            $lista = collect($this->getJson('/api/ferrakora/produtos', $regiao)->assertOk()->json('products'))->firstWhere('id', $id);
            $this->novaRequisicao();
            $pagina = $this->getJson('/api/ferrakora/produtos/'.$id, $regiao)->assertOk()->json('product');

            $this->assertSame(self::vendaFk($lista), self::vendaFk($pagina), "Lista e página do FerraKora ({$uf}).");
            if ($fixo !== null) {
                $this->assertSame((int) round($fixo * 100), self::vendaFk($pagina), 'Kit de preço fixo pelo SKU.');
            }

            // Mesmo produto na página da KazaKora, navegando da mesma região (sessão nova).
            $this->flushSession();
            $this->novaRequisicao();
            $kz = $this->get("/produtos/{$produto->slug}", $regiao)->assertOk()->viewData('page')['props']['product'];
            $this->assertSame((int) round((float) $kz['final_price'] * 100), self::vendaFk($pagina), "FerraKora e KazaKora com o mesmo preço ({$uf}).");

            // Black Friday: o card do FerraKora mostra o mesmo de/por/Pix com o cupom da KazaKora.
            $cupomFk = $pagina['variants'][0]['prices'][0]['cupom'] ?? null;
            $this->assertNotNull($cupomFk, 'BLACKFRIDAY liberado para a FerraKora: preço com o cupom no card.');
            $this->assertSame($lista['variants'][0]['prices'][0]['cupom'], $cupomFk, 'Lista e página com o mesmo preço com cupom.');
            $this->assertSame((int) round((float) $kz['preco_cupom']['de'] * 100), $cupomFk['de']);
            $this->assertSame((int) round((float) $kz['preco_cupom']['por'] * 100), $cupomFk['por']);
            $this->assertSame((int) round((float) $kz['preco_cupom']['pix'] * 100), $cupomFk['pix']);
            $this->assertArrayHasKey('parcelamento', $pagina, 'Tabela do cartão para a parcela com juros (vazia sem as taxas do Mercado Pago no teste).');

            $this->novaRequisicao();
            $comCupom = $this->postJson('/api/ferrakora/checkout/resumo', ['itens' => [['variante' => 'kzv-'.$produto->id, 'quantidade' => 1]], 'cupom' => 'BLACKFRIDAY'], $regiao)->assertOk();
            $this->assertSame($cupomFk['por'], $comCupom->json('total'), 'Checkout do FerraKora com o cupom = "por" do card.');
            $this->assertSame($cupomFk['pix'], $comCupom->json('total_pix'), 'No Pix = o Pix do card.');

            $ufNavegacao = null;
            foreach ([null, self::CEP_SP, self::CEP_CE] as $cep) {
                $this->novaRequisicao();
                $resumo = $this->postJson('/api/ferrakora/checkout/resumo', ['itens' => [['variante' => 'kzv-'.$produto->id, 'quantidade' => 1]], 'cep' => $cep], $regiao)->assertOk();
                $this->assertSame(self::vendaFk($pagina), $resumo->json('itens.0.preco_unitario'), "Checkout do FerraKora com o preço do card (navegando de {$uf}, CEP {$cep}).");
                $this->assertSame($resumo->json('subtotal') - $resumo->json('desconto_plataforma') + $resumo->json('frete'), $resumo->json('total'), 'Total = itens + linha de frete.');
                if ($fixo !== null) {
                    $this->assertSame(0, $resumo->json('frete'), 'Kit de preço fixo: frete grátis em qualquer CEP.');
                }
                $ufNavegacao = $resumo->json('uf_navegacao');
            }
            $this->assertSame($uf, $ufNavegacao);

            // O pedido chega pelo endereço do FerraKora, sem a região do IP: o site
            // devolve a uf_navegacao e o preço do item continua o do card.
            $this->novaRequisicao();
            $semIp = $this->postJson('/api/ferrakora/checkout/resumo', ['itens' => [['variante' => 'kzv-'.$produto->id, 'quantidade' => 1]], 'cep' => self::CEP_CE, 'uf_navegacao' => $uf])->assertOk();
            $this->assertSame(self::vendaFk($pagina), $semIp->json('itens.0.preco_unitario'), "Sem IP, com uf_navegacao={$uf}.");
        }
    }

    public function test_compressor_4_em_1_tem_preco_fixo_pelo_sku_no_config(): void
    {
        $config = require base_path('config/frete_embutido.php');

        $this->assertContains('344-BOMBAAR-4EM1', $config['skus_preco_fixo'], 'O 413 é "de R$ 435,52 por R$ 297,00" em qualquer CEP.');
    }

    public function test_ferrakora_cobra_na_linha_de_frete_o_que_o_cep_pede_a_mais(): void
    {
        $produto = $this->criar(['sku' => 'COMUM-1', 'price' => 200.00, 'cost_price' => null]);

        // Navegando de SP: R$ 200 + R$ 9,94. CEP do CE pede R$ 40 (sem custo, sem teto).
        $resumo = $this->postJson('/api/ferrakora/checkout/resumo', ['itens' => [['variante' => 'kzv-'.$produto->id, 'quantidade' => 1]], 'cep' => self::CEP_CE], self::SP)->assertOk();

        $resumo->assertJsonPath('itens.0.preco_unitario', 20994)
            ->assertJsonPath('frete', 3006)
            ->assertJsonPath('total', 24000)
            ->assertJsonPath('outra_regiao', true);
    }
}
