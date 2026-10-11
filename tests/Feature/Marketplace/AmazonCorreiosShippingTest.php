<?php

namespace Tests\Feature\Marketplace;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Services\CorreiosFreightQuoteService;
use App\Modules\Fiscal\Models\Company;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Models\ProductFiscalData;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Drivers\MercadoLivreDriver;
use App\Modules\Marketplace\Drivers\ShopeeDriver;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Support\CorreiosAutoShipping;
use App\Modules\Marketplace\Support\CorreiosLabelPdf;
use App\Modules\Marketplace\Support\PackageDataResolver;
use App\Services\Correios\CorreiosPrePostagemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Pedido explícito 2026-09-25: pedido da Amazon (via Bling) com NF-e
 * autorizada gera sozinho a pré-postagem dos Correios com a chave da nota,
 * caixa ou envelope conforme o produto, peso/medidas do produto — ou do
 * anúncio da Shopee, ou do Mercado Livre, nessa ordem, pelo mesmo SKU —
 * no serviço mais barato, e a etiqueta com QR sai pra impressora.
 */
class AmazonCorreiosShippingTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE = '35260965604590000107550020000017941284524944';

    protected function setUp(): void
    {
        parent::setUp();

        Company::create([
            'razao_social' => 'Kazakora', 'cnpj' => '65604590000107', 'regime_tributario' => 'mei',
            'zip' => '01000-000', 'street' => 'Rua X', 'number' => '1', 'neighborhood' => 'Centro',
            'city' => 'São Paulo', 'state' => 'SP',
        ]);
    }

    private function produto(string $sku, array $logistica): Product
    {
        $produto = Product::factory()->create(['sku' => $sku]);

        if ($logistica !== []) {
            ProductFiscalData::create(['product_id' => $produto->id, ...$logistica]);
        }

        return $produto;
    }

    /**
     * @param  array<int, array{0: Product, 1: int}>  $itens
     */
    private function pedido(array $itens, bool $comNota = true): Order
    {
        $order = Order::create([
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_AMAZON,
            'external_order_id' => '701-1234567-1234567',
            'shipping_name' => 'Maria Compradora',
            'shipping_phone' => '19999999999',
            'shipping_zip' => '13010-000',
            'shipping_street' => 'Rua das Flores',
            'shipping_number' => '10',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'Campinas',
            'shipping_state' => 'SP',
            'subtotal' => 100,
            'total' => 100,
        ]);

        foreach ($itens as [$produto, $quantidade]) {
            $order->items()->create([
                'product_id' => $produto->id,
                'product_name' => $produto->name,
                'product_price' => 50,
                'quantity' => $quantidade,
                'subtotal' => 50 * $quantidade,
            ]);
        }

        if ($comNota) {
            Invoice::create([
                'order_id' => $order->id,
                'origem' => Invoice::ORIGEM_PEDIDO,
                'status' => Invoice::STATUS_AUTHORIZED,
                'ambiente' => Invoice::AMBIENTE_PRODUCAO,
                'serie' => 1,
                'numero' => 1795,
                'valor_total' => 100,
                'chave_acesso' => self::CHAVE,
            ]);
        }

        return $order;
    }

    private function fakeCorreios(?float $pac = 30.0, ?float $sedex = 25.0): Mockery\MockInterface
    {
        $precos = Mockery::mock(CorreiosFreightQuoteService::class);
        $precos->shouldReceive('priceFor')->andReturnUsing(fn (string $servico) => $servico === CorreiosPrePostagemService::SERVICO_PAC ? $pac : $sedex);
        $this->app->instance(CorreiosFreightQuoteService::class, $precos);

        $correios = Mockery::mock(CorreiosPrePostagemService::class);
        $this->app->instance(CorreiosPrePostagemService::class, $correios);

        return $correios;
    }

    public function test_box_order_goes_with_the_invoice_key_on_the_cheapest_service(): void
    {
        $caneca = $this->produto('KZ-CANECA', ['peso_bruto' => 0.4, 'altura_cm' => 10, 'largura_cm' => 12, 'profundidade_cm' => 9]);
        $order = $this->pedido([[$caneca, 2]]);

        $correios = $this->fakeCorreios(pac: 30.0, sedex: 25.0);
        $correios->shouldReceive('create')->once()->withArgs(function (array $input) {
            return $input['invoice']['chave'] === self::CHAVE
                && $input['service_code'] === CorreiosPrePostagemService::SERVICO_SEDEX
                && $input['dimensions']['format'] === CorreiosPrePostagemService::FORMATO_CAIXA
                && $input['weight_grams'] === 800
                // Comprimento empilha (9 x 2 = 18); altura/largura pegam o maior.
                && (float) $input['dimensions']['length'] === 18.0
                && (float) $input['dimensions']['width'] === 12.0;
        })->andReturn(['id' => 'PP123', 'codigoObjeto' => 'AB123456789BR']);

        $prePostagem = app(CorreiosAutoShipping::class)->confirm($order);

        $this->assertSame(CorreiosPrePostagem::STATUS_GERADA, $prePostagem->status);
        $this->assertSame('AB123456789BR', $prePostagem->qr_payload);
        $this->assertSame(25.0, (float) $prePostagem->postage_price);

        // Segunda chamada (retry/outro disparo) devolve a mesma: nunca dois objetos.
        $this->assertSame($prePostagem->id, app(CorreiosAutoShipping::class)->confirm($order)->id);
    }

    public function test_envelope_order_only_needs_the_weight(): void
    {
        $capinha = $this->produto('KZ-CAPINHA', ['peso_bruto' => 0.05, 'formato_embalagem' => ProductFiscalData::EMBALAGEM_ENVELOPE]);
        $order = $this->pedido([[$capinha, 1]]);

        $pacote = app(PackageDataResolver::class)->forOrder($order);

        $this->assertSame(CorreiosPrePostagemService::FORMATO_ENVELOPE, $pacote['format']);
        $this->assertSame(50, $pacote['weight_grams']);
        $this->assertNull($pacote['height']);
    }

    public function test_without_an_authorized_invoice_key_nothing_is_sent_to_correios(): void
    {
        $caneca = $this->produto('KZ-CANECA', ['peso_bruto' => 0.4, 'altura_cm' => 10, 'largura_cm' => 12, 'profundidade_cm' => 9]);
        $order = $this->pedido([[$caneca, 1]], comNota: false);

        $correios = $this->fakeCorreios();
        $correios->shouldNotReceive('create');

        $this->expectException(RuntimeException::class);

        app(CorreiosAutoShipping::class)->confirm($order);
    }

    /**
     * Sem peso/medida aqui: Shopee primeiro; sem sucesso, Mercado Livre —
     * pelo mesmo SKU. O que veio fica gravado no produto.
     */
    public function test_missing_measurements_come_from_shopee_then_mercado_livre_by_sku(): void
    {
        $lampada = $this->produto('KZ-LAMPADA', ['peso_bruto' => 0.3]);
        $order = $this->pedido([[$lampada, 1]]);

        $shopee = Mockery::mock(ShopeeDriver::class);
        $shopee->shouldReceive('findItemIdBySku')->with('KZ-LAMPADA')->andReturn('999');
        $shopee->shouldReceive('fetchPackageData')->with('999')->andReturn(null);

        $ml = Mockery::mock(MercadoLivreDriver::class);
        $ml->shouldReceive('findItemIdBySku')->with('KZ-LAMPADA')->andReturn('MLB1');
        $ml->shouldReceive('fetchPackageData')->with('MLB1')->andReturn(['peso_bruto' => 0.9, 'altura_cm' => 8.0, 'largura_cm' => 15.0, 'profundidade_cm' => 20.0]);

        $manager = Mockery::mock(MarketplaceDriverManager::class);
        $manager->shouldReceive('driver')->with('shopee')->andReturn($shopee);
        $manager->shouldReceive('driver')->with('mercado_livre')->andReturn($ml);
        $this->app->instance(MarketplaceDriverManager::class, $manager);

        $pacote = app(PackageDataResolver::class)->forOrder($order);

        $this->assertSame(300, $pacote['weight_grams'], 'Peso do cadastro não é sobrescrito pelo canal.');
        $this->assertSame(20.0, $pacote['length']);

        $fiscal = $lampada->fresh()->fiscalData;
        $this->assertSame(8.0, (float) $fiscal->altura_cm);
        $this->assertSame(0.3, (float) $fiscal->peso_bruto);
    }

    /**
     * Relatório técnico 2026-09-25: 10 carregadores de 90 g = 900 g, e a
     * declaração com quantidade 10 — nunca "1 unidade".
     */
    public function test_ten_units_weigh_ten_times_and_are_declared_as_ten(): void
    {
        $carregador = $this->produto('KZ-POWERBANK-PRE', ['peso_bruto' => 0.09, 'formato_embalagem' => ProductFiscalData::EMBALAGEM_ENVELOPE]);
        $order = $this->pedido([[$carregador, 10]]);

        $correios = $this->fakeCorreios();
        $correios->shouldReceive('create')->once()->withArgs(fn (array $input) => $input['weight_grams'] === 900
            && $input['content_items'][0]['quantidade'] === 10)
            ->andReturn(['id' => 'PP1', 'codigoObjeto' => 'AA000000001BR']);

        $prePostagem = app(CorreiosAutoShipping::class)->confirm($order);

        $this->assertSame(900, $prePostagem->weight_grams);
        $this->assertSame(10, $prePostagem->content_items[0]['quantidade']);
        $this->assertSame(90, $prePostagem->content_items[0]['peso_unitario_g']);
    }

    public function test_several_skus_with_several_units_are_consolidated_in_one_package(): void
    {
        $preto = $this->produto('KZ-PB-PRE', ['peso_bruto' => 0.2, 'altura_cm' => 3, 'largura_cm' => 7, 'profundidade_cm' => 14]);
        $rosa = $this->produto('KZ-PB-ROSA', ['peso_bruto' => 0.2, 'altura_cm' => 3, 'largura_cm' => 7, 'profundidade_cm' => 14]);
        $cabo = $this->produto('KZ-CABO', ['peso_bruto' => 0.05, 'altura_cm' => 2, 'largura_cm' => 12, 'profundidade_cm' => 10]);
        $order = $this->pedido([[$preto, 2], [$rosa, 3], [$cabo, 4]]);

        $pacote = app(PackageDataResolver::class)->forOrder($order);

        // 0,2x2 + 0,2x3 + 0,05x4 = 1,2 kg
        $this->assertSame(1200, $pacote['weight_grams']);
        $this->assertSame(3.0, $pacote['height']);
        $this->assertSame(12.0, $pacote['width']);
        // 14x2 + 14x3 + 10x4
        $this->assertSame(110.0, $pacote['length']);
        $this->assertSame([2, 3, 4], array_column($pacote['items'], 'quantidade'));
    }

    public function test_item_without_linked_product_blocks_the_pre_postagem(): void
    {
        $caneca = $this->produto('KZ-CANECA', ['peso_bruto' => 0.4, 'altura_cm' => 10, 'largura_cm' => 12, 'profundidade_cm' => 9]);
        $order = $this->pedido([[$caneca, 1]]);
        $order->items()->create(['product_id' => null, 'product_name' => 'Item Amazon sem vínculo', 'product_price' => 10, 'quantity' => 1, 'subtotal' => 10]);

        $correios = $this->fakeCorreios();
        $correios->shouldNotReceive('create');

        $this->expectExceptionMessage('sem produto vinculado');

        app(CorreiosAutoShipping::class)->confirm($order->fresh());
    }

    public function test_product_without_weight_anywhere_blocks_instead_of_guessing(): void
    {
        $semPeso = $this->produto('KZ-SEM-PESO', []);
        $order = $this->pedido([[$semPeso, 1]]);

        $vazio = Mockery::mock(ShopeeDriver::class);
        $vazio->shouldReceive('findItemIdBySku')->andReturn(null);
        $manager = Mockery::mock(MarketplaceDriverManager::class);
        $manager->shouldReceive('driver')->andReturn($vazio);
        $this->app->instance(MarketplaceDriverManager::class, $manager);

        $correios = $this->fakeCorreios();
        $correios->shouldNotReceive('create');

        $this->expectExceptionMessage('sem peso_bruto');

        app(CorreiosAutoShipping::class)->confirm($order);
    }

    /**
     * Pré-postagem gerada e o pedido mudou depois (quantidade): etiqueta
     * não sai nem reimprime — cancela e gera de novo.
     */
    public function test_label_is_blocked_when_the_pre_postagem_no_longer_matches_the_order(): void
    {
        config(['services.bling.amazon_loja_id' => 206308488]);
        $carregador = $this->produto('KZ-POWERBANK-PRE', ['peso_bruto' => 0.09, 'formato_embalagem' => ProductFiscalData::EMBALAGEM_ENVELOPE]);
        $order = $this->pedido([[$carregador, 1]]);

        $correios = $this->fakeCorreios();
        $correios->shouldReceive('create')->once()->andReturn(['id' => 'PP1', 'codigoObjeto' => 'AA000000001BR']);
        $servico = app(CorreiosAutoShipping::class);
        $servico->confirm($order);

        $this->assertNull($servico->problemaDaEtiqueta($order->fresh()));

        $order->items()->first()->update(['quantity' => 10]);

        $this->assertStringContainsString('quantidades diferentes', (string) $servico->problemaDaEtiqueta($order->fresh()));
        $this->assertFalse(app(\App\Modules\Marketplace\Drivers\AmazonDriver::class)->fetchLabel($order->fresh())['ready']);
    }

    /**
     * Pedido explícito 2026-09-25: a declaração do produto (nome, cor,
     * quantidade, SKU embaixo) vai DENTRO da etiqueta dos Correios — uma
     * etiqueta só por pedido, não duas folhas.
     */
    public function test_label_pdf_is_a_single_page_with_the_product_declaration(): void
    {
        $order = $this->pedido([]);

        foreach (['Rosa' => [2, 'ROS'], 'Branco' => [4, 'BRA'], 'Preto' => [4, 'PRE']] as $cor => [$quantidade, $sigla]) {
            $produto = Product::factory()->create([
                'name' => "Carregador Portátil Power Bank 10000mah Para iPhone E Tipo C {$cor}",
                'sku' => "CAR-CAR-SEM-POW-{$sigla}-0001",
                'color' => $cor,
            ]);
            $order->items()->create(['product_id' => $produto->id, 'product_name' => $produto->name, 'product_price' => 50, 'quantity' => $quantidade, 'subtotal' => 50 * $quantidade]);
        }

        $prePostagem = CorreiosPrePostagem::create([
            'order_id' => $order->id, 'origin' => 'amazon', 'external_order_id' => '701-2931770-8321845',
            'customer_name' => 'Maria Claudia Taborda Masiero', 'zip' => '13010000', 'street' => 'Rua das Flores', 'number' => '10',
            'neighborhood' => 'Centro', 'city' => 'Campinas', 'state' => 'SP',
            'service_code' => '03298', 'service_label' => 'PAC (contrato)', 'weight_grams' => 2000,
            'dimension_format' => '2', 'content_items' => [], 'status' => CorreiosPrePostagem::STATUS_GERADA,
            'correios_id' => 'PP9', 'codigo_objeto' => 'AP540000000BR', 'qr_payload' => 'AP540000000BR',
        ]);

        $pdf = app(CorreiosLabelPdf::class)->render($prePostagem);

        $this->assertSame(1, preg_match_all('#/Type\s*/Page[^s]#', $pdf), 'Uma etiqueta só, com a declaração dentro.');
    }

    public function test_label_pdf_is_generated_for_the_printer(): void
    {
        $caneca = $this->produto('KZ-CANECA-PRE', []);
        $pote = $this->produto('KZ-POTE-VIDRO-500', []);
        $order = $this->pedido([[$caneca, 1], [$pote, 2]]);

        $prePostagem = CorreiosPrePostagem::create([
            'order_id' => $order->id, 'origin' => 'amazon', 'external_order_id' => '701-1234567-1234567',
            'customer_name' => 'Maria Compradora', 'zip' => '13010000', 'street' => 'Rua das Flores', 'number' => '10',
            'neighborhood' => 'Centro', 'city' => 'Campinas', 'state' => 'SP',
            'service_code' => '03220', 'service_label' => 'SEDEX (contrato)', 'weight_grams' => 400,
            'dimension_format' => '2', 'content_items' => [], 'status' => CorreiosPrePostagem::STATUS_GERADA,
            'correios_id' => 'PP123', 'codigo_objeto' => 'AB123456789BR', 'qr_payload' => 'AB123456789BR',
        ]);

        $pdf = app(CorreiosLabelPdf::class)->render($prePostagem);

        $this->assertStringStartsWith('%PDF-', $pdf);
    }
}
