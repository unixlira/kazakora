<?php

namespace Tests\Feature\Fiscal;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Company;
use App\Modules\Fiscal\Models\ProductFiscalData;
use App\Services\NFe\NFeXmlBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BUG REAL 2026-09-02 (pedido #1222): o Mercado Livre mandou o nome do
 * comprador com 61 caracteres (razão social duplicada e truncada pela
 * própria ML), o schema da NF-e limita xNome a 60, e o validador local do
 * sped-nfe barrava o XML antes de qualquer chamada à SEFAZ — a nota nunca
 * saiu e o pedido ficou dias sem DANFE pra baixar. A causa raiz foi
 * corrigida na origem (MercadoLivreDriver usa o BUSINESS_NAME do
 * billing_info), mas nome legítimo acima de 60 existe em qualquer canal:
 * truncar aqui garante que a nota sempre sai, com o destinatário ainda
 * identificado pelo CNPJ/CPF.
 */
class NFeXmlBuilderDestNameLengthTest extends TestCase
{
    use RefreshDatabase;

    public function test_dest_name_longer_than_sixty_characters_is_truncated_instead_of_breaking_the_xml(): void
    {
        Http::fake([
            'servicodados.ibge.gov.br/*' => Http::response([
                ['id' => 3550308, 'nome' => 'São Paulo'],
            ], 200),
        ]);

        Company::create([
            'razao_social' => 'KazaKora Comércio Ltda',
            'cnpj' => '12.345.678/0001-99',
            'inscricao_estadual' => '158.571.233.113',
            'regime_tributario' => Company::REGIME_SIMPLES_NACIONAL,
            'city' => 'São Paulo',
            'state' => 'SP',
            'street' => 'Rua Teste',
            'number' => '100',
            'neighborhood' => 'Centro',
            'zip' => '01000-000',
        ]);

        // 61 caracteres — exatamente o formato que quebrou o pedido #1222.
        $longName = '18.689.367 FABIO EDUARDO DOS S 18.689.367 FABIO EDUARDO DOS S';
        $this->assertSame(61, mb_strlen($longName));

        $order = Order::create([
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_MERCADO_LIVRE,
            'buyer_document' => '18689367000120',
            'shipping_name' => $longName,
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X',
            'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 100,
            'shipping_cost' => 0,
            'total' => 100,
        ]);

        $product = Product::factory()->create(['name' => 'Produto Real', 'sku' => 'PROD-1', 'price' => 100]);
        ProductFiscalData::create([
            'product_id' => $product->id,
            'ncm' => '12345678',
            'cfop' => '5102',
            'cfop_outros_estados' => '6108',
            'origem' => 0,
            'unidade_tributavel' => 'UN',
            'icms_situacao_tributaria' => '102',
            'pis_situacao_tributaria' => '07',
            'cofins_situacao_tributaria' => '07',
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_price' => 100,
            'quantity' => 1,
            'subtotal' => 100,
        ]);

        $result = app(NFeXmlBuilderService::class)->build($order->fresh(), 1);

        $this->assertMatchesRegularExpression('#<dest>.*?<xNome>(.{1,60})</xNome>#s', $result['xml']);
        $this->assertStringContainsString('<xNome>'.mb_substr($longName, 0, 60).'</xNome>', $result['xml']);
        $this->assertStringNotContainsString('<xNome>'.$longName.'</xNome>', $result['xml']);
    }
}
