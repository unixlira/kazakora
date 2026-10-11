<?php

namespace Tests\Feature\Fiscal;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * bling:importar-notas-autorizadas (2026-10-11): só importa nota já
 * autorizada no Bling e NUNCA transmite nada à SEFAZ.
 */
class ImportarNotasAutorizadasBlingTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE = '35260965604590000107550030000017941284524944';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config([
            'services.bling.api_base_url' => 'https://api.bling.test/Api/v3',
            'services.bling.invoice_issuer_channels' => [Order::ORIGIN_TIKTOK_SHOP],
            'services.bling.auto_send_nfe' => true,
        ]);
        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_BLING, 'status' => MarketplaceAccount::STATUS_CONNECTED,
            'access_token' => 't', 'refresh_token' => 'r', 'token_expires_at' => now()->addHour(),
            'metadata' => ['tiktok_loja_id' => 206277670],
        ]);
    }

    private function pedido(string $numero): Order
    {
        return Order::create([
            'user_id' => User::factory()->create(['role' => User::ROLE_CUSTOMER])->id,
            'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_TIKTOK_SHOP, 'external_order_id' => $numero,
            'shipping_name' => 'Cliente', 'shipping_phone' => '11999999999', 'shipping_zip' => '01001000',
            'shipping_street' => 'Rua', 'shipping_number' => '1', 'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP', 'subtotal' => 100, 'total' => 100,
            'created_at' => '2026-09-05 10:00:00',
        ]);
    }

    private function fakeBling(int $situacao): void
    {
        $xml = '<?xml version="1.0"?><nfeProc><NFe><infNFe Id="NFe'.self::CHAVE.'"></infNFe></NFe></nfeProc>';
        Http::fake([
            '*/pedidos/vendas/111' => Http::response(['data' => ['id' => 111, 'numeroLoja' => 'TK1', 'notaFiscal' => ['id' => 777]]]),
            '*/pedidos/vendas?*' => Http::response(['data' => [['id' => 111, 'numeroLoja' => 'TK1']]]),
            '*/nfe/777/enviar' => Http::response(['data' => []]),
            '*/nfe/777' => Http::response(['data' => ['id' => 777, 'numero' => '42', 'serie' => '3', 'situacao' => $situacao, 'chaveAcesso' => $situacao === 5 ? self::CHAVE : '', 'valorNota' => 100]]),
            '*/nfe/documento/*' => Http::response(['data' => ['xml' => $xml]]),
        ]);
    }

    private function assertNadaTransmitido(): void
    {
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/enviar'));
    }

    public function test_simulacao_nao_grava_e_gravar_importa_a_autorizada(): void
    {
        $this->fakeBling(5);
        $order = $this->pedido('TK1');

        $this->artisan('bling:importar-notas-autorizadas')->assertSuccessful();
        $this->assertNull($order->fresh()->invoice);

        $this->artisan('bling:importar-notas-autorizadas', ['--gravar' => true])->assertSuccessful();
        $invoice = $order->fresh()->invoice;
        $this->assertSame(Invoice::STATUS_AUTHORIZED, $invoice->status);
        $this->assertSame(3, (int) $invoice->serie);
        $this->assertSame(42, (int) $invoice->numero);
        $this->assertNadaTransmitido();
    }

    public function test_pendente_no_bling_nao_e_transmitida_nem_gravada(): void
    {
        $this->fakeBling(1);
        $order = $this->pedido('TK1');

        $this->artisan('bling:importar-notas-autorizadas', ['--gravar' => true])->assertSuccessful();

        $this->assertNull($order->fresh()->invoice);
        $this->assertNadaTransmitido();
    }

    public function test_pula_pedido_com_nota_nossa_serie_2(): void
    {
        $this->fakeBling(5);
        $order = $this->pedido('TK1');
        Invoice::create(['order_id' => $order->id, 'serie' => 2, 'numero' => 2071, 'status' => Invoice::STATUS_PENDING, 'origem' => Invoice::ORIGEM_PEDIDO, 'ambiente' => Invoice::AMBIENTE_PRODUCAO]);

        $this->artisan('bling:importar-notas-autorizadas', ['--gravar' => true])->assertSuccessful();

        $this->assertSame(2071, (int) $order->fresh()->invoice->numero);
        Http::assertNothingSent();
    }
}
