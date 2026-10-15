<?php

namespace Tests\Feature\Marketplace;

use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Drivers\ShopeeDriver;
use App\Modules\Marketplace\Models\ChannelInvoiceSubmission;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\ChannelInvoiceSubmissionService;
use App\Services\Shopee\Exceptions\ShopeeException;
use App\Services\Shopee\ShopeeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * BUG REAL 2026-10-15 (Shopee #3140, #3141, #3142): a nota foi aceita, a
 * Shopee organizou o envio e um segundo envio da mesma nota foi recusado
 * ("Upload is not accepted after shipment is arranged"), gravando ERRO por
 * cima do SENT e travando confirmação de envio e etiqueta.
 */
class ShopeeNotaJaEnviadaTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE = '35261065604590000107550020000030861189638570';

    private function pedidoComNota(): Order
    {
        Storage::fake('local');
        Storage::disk('local')->put('nfe/teste.xml', '<nfeProc/>');

        $order = Order::create([
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_SHOPEE,
            'external_order_id' => '251014ABCDEF',
            'shipping_name' => 'Cliente',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X',
            'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 89.99,
            'total' => 89.99,
        ]);

        Invoice::create([
            'order_id' => $order->id,
            'status' => Invoice::STATUS_AUTHORIZED,
            'serie' => 2,
            'numero' => 3086,
            'valor_total' => 89.99,
            'chave_acesso' => self::CHAVE,
            'xml_path' => 'nfe/teste.xml',
        ]);

        return $order->fresh();
    }

    public function test_envio_automatico_nao_reenvia_nota_que_o_canal_ja_recebeu(): void
    {
        $order = $this->pedidoComNota();
        ChannelInvoiceSubmission::create([
            'order_id' => $order->id,
            'invoice_id' => $order->invoice->id,
            'channel' => $order->origin,
            'status' => ChannelInvoiceSubmission::STATUS_SENT,
        ]);

        $manager = Mockery::mock(MarketplaceDriverManager::class);
        $manager->shouldNotReceive('driver');
        $this->app->instance(MarketplaceDriverManager::class, $manager);

        $submission = app(ChannelInvoiceSubmissionService::class)->submit($order);

        $this->assertSame(ChannelInvoiceSubmission::STATUS_SENT, $submission->status);
    }

    public function test_recusa_depois_do_envio_organizado_com_a_mesma_chave_na_shopee_conta_como_enviada(): void
    {
        Queue::fake();
        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_SHOPEE,
            'status' => MarketplaceAccount::STATUS_CONNECTED,
            'seller_id' => '123',
            'access_token' => 'fake',
            'refresh_token' => 'fake',
            'token_expires_at' => now()->addHours(4),
            'connected_at' => now(),
        ]);
        $order = $this->pedidoComNota();

        $client = Mockery::mock(ShopeeClient::class);
        $client->shouldReceive('postMultipart')->once()
            ->andThrow(new ShopeeException('Wrong parameters, detail: Upload invoice failed. Upload is not accepted after shipment is arranged.'));
        $client->shouldReceive('get')->with('/api/v2/order/get_order_detail', Mockery::any())->once()
            ->andReturn(['response' => ['order_list' => [['order_sn' => '251014ABCDEF', 'invoice_data' => ['access_key' => self::CHAVE, 'status' => 'valid']]]]]);

        $resultado = (new ShopeeDriver($client))->submitInvoice($order, $order->invoice);

        $this->assertSame('sent', $resultado['status']);
    }

    public function test_recusa_sem_a_nossa_chave_na_shopee_continua_erro(): void
    {
        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_SHOPEE,
            'status' => MarketplaceAccount::STATUS_CONNECTED,
            'seller_id' => '123',
            'access_token' => 'fake',
            'refresh_token' => 'fake',
            'token_expires_at' => now()->addHours(4),
            'connected_at' => now(),
        ]);
        $order = $this->pedidoComNota();

        $client = Mockery::mock(ShopeeClient::class);
        $client->shouldReceive('postMultipart')->once()->andThrow(new ShopeeException('This order cannot accept invoices yet'));
        $client->shouldReceive('get')->once()->andReturn(['response' => ['order_list' => [['invoice_data' => null]]]]);

        $resultado = (new ShopeeDriver($client))->submitInvoice($order, $order->invoice);

        $this->assertSame('error', $resultado['status']);
    }
}
