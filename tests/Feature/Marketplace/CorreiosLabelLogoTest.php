<?php

namespace Tests\Feature\Marketplace;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Support\CorreiosLabelPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pedido do Lira 2026-10-16: pedido do site kazakora.com sai com a MESMA
 * etiqueta dos pedidos da Amazon, só trocando o logo da Amazon pelo da
 * KazaKora. FerraKora e Beauty Kora (origin "loja" com site_origem) e os
 * demais canais continuam sem logo.
 */
class CorreiosLabelLogoTest extends TestCase
{
    use RefreshDatabase;

    private function etiqueta(string $origin, ?string $siteOrigem = null): string
    {
        $order = Order::create([
            'status' => Order::STATUS_PAID,
            'origin' => $origin,
            'site_origem' => $siteOrigem,
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

        $prePostagem = CorreiosPrePostagem::create([
            'order_id' => $order->id, 'origin' => $origin,
            'customer_name' => 'Maria Compradora', 'zip' => '13010000', 'street' => 'Rua das Flores', 'number' => '10',
            'neighborhood' => 'Centro', 'city' => 'Campinas', 'state' => 'SP',
            'service_code' => '03298', 'service_label' => 'PAC (contrato)', 'weight_grams' => 300,
            'dimension_format' => '2', 'content_items' => [], 'status' => CorreiosPrePostagem::STATUS_GERADA,
            'correios_id' => 'PP1', 'codigo_objeto' => 'AP000000001BR', 'qr_payload' => 'AP000000001BR',
        ]);

        return app(CorreiosLabelPdf::class)->render($prePostagem);
    }

    private function imagens(string $pdf): int
    {
        return preg_match_all('#/Subtype\s*/Image#', $pdf);
    }

    public function test_site_kazakora_order_gets_the_kazakora_logo(): void
    {
        $this->assertFileExists(resource_path('etiquetas/kazakora.png'));

        $pdf = $this->etiqueta(Order::ORIGIN_STORE);

        $this->assertSame(1, $this->imagens($pdf));
        $this->assertSame(1, preg_match_all('#/Type\s*/Page[^s]#', $pdf), 'Continua uma etiqueta só.');
    }

    public function test_amazon_order_keeps_the_amazon_logo(): void
    {
        $this->assertSame(1, $this->imagens($this->etiqueta(Order::ORIGIN_AMAZON)));
    }

    public function test_other_sites_and_channels_get_no_logo(): void
    {
        $this->assertSame(0, $this->imagens($this->etiqueta(Order::ORIGIN_STORE, Order::SITE_FERRAKORA)));
        $this->assertSame(0, $this->imagens($this->etiqueta(Order::ORIGIN_MERCADO_LIVRE)));
    }
}
