<?php

namespace Tests\Feature\Fiscal;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Fiscal\Models\Company;
use App\Modules\Fiscal\Models\ProductFiscalData;
use App\Services\NFe\NFeXmlBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Pedido explícito 2026-08-09: emissão manual de nota fiscal precisa aceitar
 * item digitado na hora (produto fora do catálogo ou serviço avulso), não
 * só produto real do catálogo — sem gente essa era a única cobertura de
 * teste automatizado que NFeXmlBuilderService::build() já tinha até aqui
 * (nenhuma, ver histórico do projeto).
 */
class NFeXmlBuilderServiceFreeformItemTest extends TestCase
{
    use RefreshDatabase;

    private function makeCompany(): Company
    {
        return Company::create([
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
    }

    private function makeOrder(array $attributes = []): Order
    {
        return Order::create(array_merge([
            'status' => Order::STATUS_COMPLETED,
            'origin' => Order::ORIGIN_MANUAL_INVOICE,
            'buyer_document' => '123.456.789-09',
            'shipping_name' => 'Cliente Teste',
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
        ], $attributes));
    }

    private function fakeIbge(): void
    {
        Http::fake([
            'servicodados.ibge.gov.br/*' => Http::response([
                ['id' => 3550308, 'nome' => 'São Paulo'],
            ], 200),
        ]);
    }

    public function test_build_still_works_for_a_real_catalog_product_with_fiscal_data(): void
    {
        $this->fakeIbge();
        $this->makeCompany();

        $product = Product::factory()->create(['name' => 'Produto Real', 'sku' => 'PROD-1', 'price' => 50]);
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

        $order = $this->makeOrder();
        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_price' => 50,
            'quantity' => 2,
            'subtotal' => 100,
        ]);

        $result = app(NFeXmlBuilderService::class)->build($order->fresh(), 1);

        $this->assertStringContainsString('Venda de mercadoria', $result['xml']);
        $this->assertStringContainsString('PROD-1', $result['xml']);
        $this->assertNotEmpty($result['chave']);
    }

    public function test_build_accepts_a_freeform_service_item_without_a_catalog_product(): void
    {
        $this->fakeIbge();
        $this->makeCompany();

        $order = $this->makeOrder();
        $order->items()->create([
            'product_id' => null,
            'product_name' => 'Consultoria técnica avulsa',
            'product_price' => 100,
            'quantity' => 1,
            'subtotal' => 100,
            'item_type' => OrderItem::TYPE_SERVICE,
            'ncm' => '99999999',
            'cfop' => '5933',
            'cfop_outros_estados' => '6933',
            'origem_mercadoria' => 0,
            'unidade_tributavel' => 'UN',
            'icms_situacao_tributaria' => '400',
            'pis_situacao_tributaria' => '07',
            'cofins_situacao_tributaria' => '07',
        ]);

        $result = app(NFeXmlBuilderService::class)->build($order->fresh(), 1);

        // Todos os itens são serviço — natOp muda em relação ao caso de produto.
        $this->assertStringContainsString('Prestação de serviço', $result['xml']);
        $this->assertStringContainsString('Consultoria técnica avulsa', $result['xml']);
        $this->assertStringContainsString('99999999', $result['xml']);
        $this->assertNotEmpty($result['chave']);
    }

    public function test_build_keeps_venda_de_mercadoria_when_order_mixes_product_and_service_items(): void
    {
        $this->fakeIbge();
        $this->makeCompany();

        $product = Product::factory()->create(['name' => 'Produto Misto', 'sku' => 'PROD-2', 'price' => 30]);
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

        $order = $this->makeOrder();
        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'product_price' => 30,
            'quantity' => 1,
            'subtotal' => 30,
        ]);
        $order->items()->create([
            'product_id' => null,
            'product_name' => 'Instalação avulsa',
            'product_price' => 70,
            'quantity' => 1,
            'subtotal' => 70,
            'item_type' => OrderItem::TYPE_SERVICE,
            'ncm' => '99999999',
            'cfop' => '5933',
            'origem_mercadoria' => 0,
            'unidade_tributavel' => 'UN',
            'icms_situacao_tributaria' => '400',
            'pis_situacao_tributaria' => '07',
            'cofins_situacao_tributaria' => '07',
        ]);

        $result = app(NFeXmlBuilderService::class)->build($order->fresh(), 1);

        $this->assertStringContainsString('Venda de mercadoria', $result['xml']);
    }

    public function test_build_throws_when_a_freeform_item_is_missing_ncm(): void
    {
        $this->fakeIbge();
        $this->makeCompany();

        $order = $this->makeOrder();
        $order->items()->create([
            'product_id' => null,
            'product_name' => 'Item sem NCM',
            'product_price' => 10,
            'quantity' => 1,
            'subtotal' => 10,
            'item_type' => OrderItem::TYPE_SERVICE,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('não tem dados fiscais');

        app(NFeXmlBuilderService::class)->build($order->fresh(), 1);
    }
}
