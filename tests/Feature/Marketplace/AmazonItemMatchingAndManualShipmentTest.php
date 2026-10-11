<?php

namespace Tests\Feature\Marketplace;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Drivers\AmazonDriver;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\CorreiosAutoShipping;
use App\Services\Bling\BlingOrderService;
use App\Services\Correios\CorreiosPrePostagemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

/**
 * Achados reais de 25/09/2026 nos primeiros pedidos da Amazon pelo Bling:
 * - #2504: a Amazon mandou códigos que não são nossos SKUs — itens sem
 *   produto, sem foto e sem SKU no KoraSync;
 * - #2451/#2485: pré-postagem de antes do retrato por produto aparecia
 *   como "logística travada" à toa;
 * - um pedido já tinha sido despachado à mão pelo Bling — o sistema não
 *   pode gerar outra postagem nem sobrescrever o rastreio.
 */
class AmazonItemMatchingAndManualShipmentTest extends TestCase
{
    use RefreshDatabase;

    private const BLING_ID = 22334455667;

    private const NUMERO = '701-2931770-8321845';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.bling.api_base_url' => 'https://api.bling.test/Api/v3',
            'services.bling.amazon_loja_id' => 206308488,
        ]);

        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_BLING, 'status' => MarketplaceAccount::STATUS_CONNECTED,
            'access_token' => 't', 'refresh_token' => 'r', 'token_expires_at' => now()->addHour(),
        ]);

        foreach (['Branco' => 'BRA', 'Preto' => 'PRE', 'Rosa' => 'ROS', 'Verde' => 'VER'] as $cor => $sigla) {
            Product::factory()->create([
                'name' => "Carregador Portátil Power Bank 10000mah Para iPhone E Tipo C {$cor}",
                'sku' => "CAR-CAR-SEM-POW-{$sigla}-0001", 'color' => $cor, 'is_active' => true,
            ]);
        }
    }

    private function pedido(): Order
    {
        return Order::create([
            'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_AMAZON, 'external_order_id' => self::NUMERO,
            'shipping_name' => 'Maria', 'shipping_phone' => '1', 'shipping_zip' => '13010000', 'shipping_street' => 'Rua',
            'shipping_number' => '1', 'shipping_neighborhood' => 'Centro', 'shipping_city' => 'Campinas', 'shipping_state' => 'SP',
            'subtotal' => 100, 'total' => 100,
        ]);
    }

    private function itemSemVinculo(Order $order, string $codigo, string $nome): void
    {
        $order->items()->create(['product_id' => null, 'external_item_id' => $codigo, 'product_name' => $nome, 'product_price' => 50, 'quantity' => 4, 'subtotal' => 200]);
    }

    public function test_color_in_the_amazon_title_picks_the_right_variation(): void
    {
        $this->itemSemVinculo($this->pedido(), 'AMZ-X1', 'Mini Power Bank 10000mah Para USB - C E Tipo C Preto');

        $produto = app(AmazonDriver::class)->autoImportProduct('AMZ-X1');

        $this->assertSame('CAR-CAR-SEM-POW-PRE-0001', $produto?->sku);
    }

    public function test_title_without_color_is_left_for_a_person_to_link(): void
    {
        $this->itemSemVinculo($this->pedido(), 'AMZ-X2', 'Carregador portátil Celular Mini Power Bank 10000mAh para iPhone e Tipo C com Suporte Compatível tablet e outros');

        $this->assertNull(app(AmazonDriver::class)->autoImportProduct('AMZ-X2'));
    }

    /**
     * #2504: vinculado o último item, a nota não pode esperar a rodada de
     * 15 min — sai na hora.
     */
    public function test_linking_the_last_unlinked_item_issues_the_invoice_right_away(): void
    {
        \Illuminate\Support\Facades\Queue::fake([\App\Modules\Fiscal\Jobs\GenerateInvoiceJob::class]);
        $order = $this->pedido();
        $this->itemSemVinculo($order, 'AMZ-X1', 'Mini Power Bank 10000mah Para USB - C E Tipo C Preto');
        $this->itemSemVinculo($order, 'AMZ-X2', 'Carregador portátil Celular Mini Power Bank com Suporte');

        $this->artisan('marketplace:relink-unmapped-items');

        // Um item continua sem cor: nada de nota ainda.
        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Modules\Fiscal\Jobs\GenerateInvoiceJob::class);

        $order->items()->whereNull('product_id')->update(['product_id' => Product::where('sku', 'CAR-CAR-SEM-POW-ROS-0001')->value('id')]);
        \App\Modules\Fiscal\Jobs\GenerateInvoiceJob::seDestravou($order->fresh());

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Modules\Fiscal\Jobs\GenerateInvoiceJob::class, 1);
    }

    public function test_sku_match_ignores_case_and_spaces(): void
    {
        $this->assertSame('CAR-CAR-SEM-POW-ROS-0001', app(AmazonDriver::class)->autoImportProduct(' car-car-sem-pow-ros-0001 ')?->sku);
    }

    public function test_pre_postagem_without_per_product_snapshot_is_not_flagged_when_units_match(): void
    {
        $order = $this->pedido();
        $produto = Product::where('sku', 'CAR-CAR-SEM-POW-BRA-0001')->first();
        $order->items()->create(['product_id' => $produto->id, 'product_name' => $produto->name, 'product_price' => 50, 'quantity' => 1, 'subtotal' => 50]);

        $antiga = CorreiosPrePostagem::create([
            'order_id' => $order->id, 'origin' => 'amazon', 'customer_name' => 'Maria', 'zip' => '13010000', 'street' => 'Rua',
            'number' => '1', 'neighborhood' => 'Centro', 'city' => 'Campinas', 'state' => 'SP', 'service_code' => '03298',
            'service_label' => 'PAC (contrato)', 'weight_grams' => 300, 'dimension_format' => '2',
            'content_items' => [['conteudo' => $produto->name, 'quantidade' => 1, 'valor' => 50]],
            'status' => CorreiosPrePostagem::STATUS_GERADA, 'correios_id' => 'PP1', 'codigo_objeto' => 'AP538309458BR', 'qr_payload' => 'AP538309458BR',
        ]);

        $this->assertNull(app(CorreiosAutoShipping::class)->problemaDaEtiqueta($order->fresh(), $antiga));
    }

    public function test_order_already_shipped_by_hand_in_bling_gets_no_second_postage(): void
    {
        Http::fake([
            '*/pedidos/vendas?*' => Http::response(['data' => []]),
            '*/pedidos/vendas/'.self::BLING_ID => Http::response(['data' => [
                'id' => self::BLING_ID, 'numeroLoja' => self::NUMERO,
                'transporte' => ['volumes' => [['id' => 1, 'codigoRastreamento' => 'QB123456789BR']]],
            ]]),
        ]);
        app(BlingOrderService::class)->rememberOrderId(self::NUMERO, self::BLING_ID);

        $correios = Mockery::mock(CorreiosPrePostagemService::class);
        $correios->shouldNotReceive('create');
        $this->app->instance(CorreiosPrePostagemService::class, $correios);

        $order = $this->pedido();
        $resultado = app(AmazonDriver::class)->confirmShipping($order);

        $this->assertSame('QB123456789BR', $resultado['tracking_code']);
        $this->assertSame(Order::STATUS_SHIPPED, $order->fresh()->status, 'Sai da fila: já foi despachado.');
    }
}
