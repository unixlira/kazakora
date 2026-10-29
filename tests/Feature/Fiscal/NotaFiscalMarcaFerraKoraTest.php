<?php

namespace Tests\Feature\Fiscal;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Company;
use App\Modules\Fiscal\Models\ProductFiscalData;
use App\Services\NFe\NFeDanfeService;
use App\Services\NFe\NFeXmlBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pedido do Lira 2026-10-28: a nota do pedido #3619 (site FerraKora) chegou
 * com "Pedido #3619 - KazaKora" e o logo da KazaKora no DANFE. Pedido do
 * site FerraKora sai com o nome e o logo dela; o emitente (CNPJ) é o mesmo.
 */
class NotaFiscalMarcaFerraKoraTest extends TestCase
{
    use RefreshDatabase;

    private function pedido(?string $site): Order
    {
        config(['nfe.ambiente' => 'producao']);

        Http::fake([
            'servicodados.ibge.gov.br/*' => Http::response([['id' => 3550308, 'nome' => 'São Paulo']], 200),
        ]);

        Company::firstOrCreate(['cnpj' => '12.345.678/0001-99'], [
            'razao_social' => 'Empresa Teste',
            'inscricao_estadual' => '158.571.233.113',
            'regime_tributario' => Company::REGIME_SIMPLES_NACIONAL,
            'city' => 'São Paulo',
            'state' => 'SP',
            'street' => 'Rua Teste',
            'number' => '100',
            'neighborhood' => 'Centro',
            'zip' => '01000-000',
        ]);

        $order = Order::create([
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_STORE,
            'site_origem' => $site,
            'buyer_document' => '52998224725',
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
        ]);

        $product = Product::factory()->create(['price' => 100]);
        ProductFiscalData::create([
            'product_id' => $product->id,
            'ncm' => '12345678',
            'cfop' => '5102',
            'cfop_outros_estados' => '6102',
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

        return $order->fresh();
    }

    public function test_nota_do_site_ferrakora_sai_com_ferrakora(): void
    {
        $order = $this->pedido(Order::SITE_FERRAKORA);

        $xml = app(NFeXmlBuilderService::class)->build($order, 1)['xml'];

        $this->assertStringContainsString("<infCpl>Pedido #{$order->id} - FerraKora</infCpl>", $xml);
        $this->assertStringNotContainsString('KazaKora', $xml);
    }

    public function test_nota_da_kazakora_continua_kazakora(): void
    {
        $order = $this->pedido(null);

        $xml = app(NFeXmlBuilderService::class)->build($order, 1)['xml'];

        $this->assertStringContainsString("<infCpl>Pedido #{$order->id} - KazaKora</infCpl>", $xml);
    }

    public function test_danfe_da_ferrakora_usa_o_logo_dela(): void
    {
        $this->assertFileExists(resource_path('nfe/ferrakora.png'));

        $order = $this->pedido(Order::SITE_FERRAKORA);
        $xml = app(NFeXmlBuilderService::class)->build($order, 1)['xml'];

        $danfe = app(NFeDanfeService::class);
        $ferrakora = $danfe->generate($xml, $order);
        $kazakora = $danfe->generate($xml);

        $this->assertStringStartsWith('%PDF', $ferrakora);
        $this->assertStringStartsWith('%PDF', $kazakora);
        // Logos diferentes viram imagens diferentes dentro do PDF.
        $this->assertNotSame(strlen($ferrakora), strlen($kazakora));
    }
}
