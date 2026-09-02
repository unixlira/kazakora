<?php

namespace Tests\Feature\Fiscal;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Services\Bling\BlingInvoiceImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pedido explícito 2026-09-02: "o kazakora tem que ter a nota que o bling
 * gerou tbm... deixar disponível pdf e xml". Desde essa data a NF-e do
 * TikTok Shop é emitida pelo Bling (único jeito do XML chegar ao canal e a
 * etiqueta liberar), então sem este importador o pedido ficaria sem
 * registro de nota nenhum deste lado.
 */
class BlingInvoiceImporterTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE = '35260965604590000107550020000017941284524944';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config([
            'services.bling.api_base_url' => 'https://api.bling.test/Api/v3',
            'services.bling.invoice_issuer_channels' => [Order::ORIGIN_TIKTOK_SHOP],
        ]);

        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_BLING,
            'status' => MarketplaceAccount::STATUS_CONNECTED,
            'access_token' => 'token-de-teste',
            'refresh_token' => 'refresh-de-teste',
            'token_expires_at' => now()->addHour(),
            'metadata' => ['tiktok_loja_id' => 206277670],
        ]);
    }

    private function makeOrder(): Order
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        return Order::create([
            'user_id' => $user->id,
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_TIKTOK_SHOP,
            'external_order_id' => '585846882986263720',
            'shipping_name' => 'Daniella Fachim',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '87780-000',
            'shipping_street' => 'Tocantins',
            'shipping_number' => '87',
            'shipping_neighborhood' => 'Tormena',
            'shipping_city' => 'Paraíso do Norte',
            'shipping_state' => 'PR',
            'subtotal' => 100,
            'total' => 100,
        ]);
    }

    public function test_authorized_invoice_issued_by_bling_is_saved_here_with_xml(): void
    {
        $xml = '<?xml version="1.0"?><nfeProc><NFe><infNFe Id="NFe'.self::CHAVE.'"></infNFe></NFe></nfeProc>';

        Http::fake([
            // A busca é em duas etapas: lista da loja e depois o pedido
            // por id (ver BlingOrderService::findByOrderNumber).
            '*/pedidos/vendas/26759086886' => Http::response(['data' => [
                'id' => 26759086886,
                'numeroLoja' => '585846882986263720',
                'notaFiscal' => ['id' => 55501],
                'situacao' => ['id' => 894762],
            ]]),
            '*/pedidos/vendas?*' => Http::response(['data' => [[
                'id' => 26759086886,
                'numeroLoja' => '585846882986263720',
            ]]]),
            '*/nfe/55501' => Http::response(['data' => [
                'id' => 55501,
                'numero' => '1795',
                'serie' => '1',
                'situacao' => 5,
                'chaveAcesso' => self::CHAVE,
                'valorNota' => 100.0,
            ]]),
            '*/nfe/documento/*' => Http::response(['data' => ['xml' => $xml]]),
        ]);

        $order = $this->makeOrder();

        $invoice = app(BlingInvoiceImporter::class)->syncForOrder($order);

        $this->assertNotNull($invoice, 'A nota emitida no Bling tem que existir aqui.');
        $this->assertSame(Invoice::STATUS_AUTHORIZED, $invoice->status);
        $this->assertSame(1795, $invoice->numero);
        $this->assertSame(self::CHAVE, $invoice->chave_acesso);
        $this->assertSame($order->id, $invoice->order_id);

        // Mesmo caminho das notas emitidas por nós — é o que faz as telas e
        // as rotas de download funcionarem sem exceção pra nota do Bling.
        $this->assertSame("invoices/{$order->id}/nfe-".self::CHAVE.'.xml', $invoice->xml_path);
        Storage::disk('local')->assertExists($invoice->xml_path);
        $this->assertSame($xml, Storage::disk('local')->get($invoice->xml_path));
    }

    /**
     * A emissão no Bling é assíncrona: no instante em que o pedido chega
     * pelo webhook, quase nunca existe nota. Isso não é erro — a varredura
     * invoices:sync-bling tenta de novo.
     */
    public function test_order_without_an_invoice_in_bling_yet_records_nothing(): void
    {
        Http::fake([
            '*/pedidos/vendas/26759086886' => Http::response(['data' => [
                'id' => 26759086886,
                'numeroLoja' => '585846882986263720',
                'notaFiscal' => ['id' => 0],
            ]]),
            '*/pedidos/vendas?*' => Http::response(['data' => [[
                'id' => 26759086886,
                'numeroLoja' => '585846882986263720',
            ]]]),
        ]);

        $order = $this->makeOrder();

        $this->assertNull(app(BlingInvoiceImporter::class)->syncForOrder($order));
        $this->assertNull($order->fresh()->invoice);
    }

    /** Falha do Bling não pode derrubar o fluxo — o importador roda dentro do webhook. */
    public function test_failure_on_the_bling_side_never_throws(): void
    {
        Http::fake(['*' => Http::response(['error' => ['type' => 'SERVER_ERROR']], 500)]);

        $order = $this->makeOrder();

        $this->assertNull(app(BlingInvoiceImporter::class)->syncForOrder($order));
    }
}
