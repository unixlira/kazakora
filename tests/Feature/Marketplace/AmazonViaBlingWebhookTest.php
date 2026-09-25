<?php

namespace Tests\Feature\Marketplace;

use App\Jobs\ProcessBlingOrderWebhook;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Marketplace\Models\ChannelWebhookLog;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Pedido explícito 2026-09-25: a Amazon (loja "KoraMix Shop") está
 * conectada ao Bling, não por SP-API — o pedido chega pelo webhook do
 * Bling e tem que virar um Order da Amazon igual a qualquer outro canal
 * (KoraSync, estoque), com a NF-e emitida por NÓS, não pelo Bling.
 */
class AmazonViaBlingWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const LOJA_AMAZON = 206308488;

    private const NUMERO_AMAZON = '701-1234567-1234567';

    private const BLING_ID = 22334455667;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.bling.api_base_url' => 'https://api.bling.test/Api/v3',
            'services.bling.amazon_loja_id' => self::LOJA_AMAZON,
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

    /**
     * @return array<string, mixed>
     */
    private function payload(string $event = 'order.created'): array
    {
        return [
            'eventId' => 'evento-amazon-1',
            'event' => $event,
            'data' => [
                'id' => self::BLING_ID,
                'numeroLoja' => self::NUMERO_AMAZON,
                'loja' => ['id' => self::LOJA_AMAZON],
                'situacao' => ['id' => 6],
            ],
        ];
    }

    private function fakeBlingOrder(): void
    {
        Http::fake([
            '*/pedidos/vendas/'.self::BLING_ID => Http::response(['data' => [
                'id' => self::BLING_ID,
                'numeroLoja' => self::NUMERO_AMAZON,
                'data' => '2026-09-25',
                'total' => 149.90,
                'loja' => ['id' => self::LOJA_AMAZON],
                'situacao' => ['id' => 6],
                'contato' => ['id' => 1111, 'nome' => 'Maria Compradora', 'numeroDocumento' => '123.456.789-09'],
                'desconto' => ['valor' => 0],
                'itens' => [[
                    'codigo' => 'KZ-GARRAFA-001',
                    'descricao' => 'Garrafa Térmica 500ml',
                    'quantidade' => 1,
                    'valor' => 129.90,
                ]],
                'transporte' => [
                    'frete' => 20.00,
                    'etiqueta' => [
                        'endereco' => 'Rua das Flores', 'numero' => '10', 'bairro' => 'Centro',
                        'municipio' => 'Campinas', 'uf' => 'SP', 'cep' => '13010-000',
                    ],
                    'volumes' => [['codigoRastreamento' => '']],
                ],
            ]]),
            // Varredura de 60 dias da loja (BlingOrderService::findByOrderNumber).
            '*/pedidos/vendas?*' => Http::response(['data' => []]),
            '*/situacoes/6' => Http::response(['data' => ['id' => 6, 'nome' => 'Em aberto']]),
            '*/contatos/1111' => Http::response(['data' => ['celular' => '19999999999', 'email' => 'maria@example.com']]),
        ]);
    }

    private function runJob(array $payload): void
    {
        $log = ChannelWebhookLog::create([
            'channel' => MarketplaceAccount::CHANNEL_AMAZON,
            'event_type' => $payload['event'],
            'payload' => $payload,
            'headers' => [],
            'signature_valid' => true,
            'status' => ChannelWebhookLog::STATUS_RECEIVED,
        ]);

        dispatch_sync(new ProcessBlingOrderWebhook($payload, $log->id));
    }

    public function test_amazon_order_from_bling_becomes_an_amazon_order_with_our_own_invoice(): void
    {
        Queue::fake([GenerateInvoiceJob::class, \App\Modules\Marketplace\Jobs\ConfirmChannelShippingJob::class]);
        $this->fakeBlingOrder();

        $product = Product::factory()->create(['sku' => 'KZ-GARRAFA-001', 'stock' => 5]);

        $this->runJob($this->payload());

        $order = Order::query()->where('origin', Order::ORIGIN_AMAZON)->where('external_order_id', self::NUMERO_AMAZON)->first();

        $this->assertNotNull($order, 'O pedido da Amazon tem que existir aqui, com o número da Amazon.');
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertSame(149.90, (float) $order->total);
        $this->assertSame('12345678909', $order->buyer_document);
        $this->assertSame($product->id, $order->items->first()->product_id, 'SKU exato do Bling casa com o catálogo.');

        // NF-e é nossa pra Amazon — e nada de buscar nota no Bling (nota em dobro).
        Queue::assertPushed(GenerateInvoiceJob::class);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/nfe'));
    }

    /**
     * O mesmo pedido chegando 2x (created + updated, ou reentrega) não
     * pode virar dois pedidos.
     */
    public function test_reprocessing_the_same_amazon_order_does_not_duplicate_it(): void
    {
        Queue::fake([GenerateInvoiceJob::class, \App\Modules\Marketplace\Jobs\ConfirmChannelShippingJob::class]);
        $this->fakeBlingOrder();
        Product::factory()->create(['sku' => 'KZ-GARRAFA-001', 'stock' => 5]);

        $this->runJob($this->payload());
        $this->runJob($this->payload('order.updated'));

        $this->assertSame(1, Order::query()->where('origin', Order::ORIGIN_AMAZON)->count());
    }

    public function test_order_deleted_in_bling_does_not_import_nor_touch_anything(): void
    {
        Http::fake();

        $this->runJob($this->payload('order.deleted'));

        $this->assertSame(0, Order::query()->count());
        Http::assertNothingSent();
    }
}
