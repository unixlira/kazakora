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
 * BUG REAL 2026-10-01: produto com cadastro fiscal sem CST de PIS/COFINS
 * gerava <PIS/> vazio e a SEFAZ recusava a nota (2 vendas da Shopee sem
 * nota e sem etiqueta).
 */
class NFeXmlBuilderPisCofinsCstTest extends TestCase
{
    use RefreshDatabase;

    public function test_produto_sem_cst_de_pis_cofins_sai_com_o_padrao_em_vez_de_pis_vazio(): void
    {
        // Produção de propósito: em homologação (default do config/nfe.php)
        // o próprio sped-nfe troca QUALQUER xNome do destinatário por "NF-E
        // EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL" (exigência
        // da SEFAZ), e o nome truncado nunca chegaria no XML pra ser
        // conferido. Só monta o XML — nada é transmitido.
        config(['nfe.ambiente' => 'producao']);

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
            'pis_situacao_tributaria' => null,
            'cofins_situacao_tributaria' => '8',
        ]);

        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_price' => 100,
            'quantity' => 1,
            'subtotal' => 100,
        ]);

        $result = app(NFeXmlBuilderService::class)->build($order->fresh(), 1);

        $this->assertStringContainsString('<PIS><PISNT><CST>08</CST></PISNT></PIS>', $result['xml']);
        $this->assertStringContainsString('<COFINS><COFINSNT><CST>08</CST></COFINSNT></COFINS>', $result['xml']);
    }
}
