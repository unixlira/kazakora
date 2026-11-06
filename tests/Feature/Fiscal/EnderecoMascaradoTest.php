<?php

namespace Tests\Feature\Fiscal;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Services\InvoiceService;
use App\Modules\Marketplace\Drivers\MarketplaceChannelDriver;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Support\MercadoLivreFull;
use App\Modules\Marketplace\Support\OrderImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * Caso real 2026-11-05, pedido #3838: o Mercado Livre devolveu o endereço
 * como "XXXXXXX" logo depois da venda, a NF-e 2-3489 saiu com ele e o ML
 * recusou ("NFe receiver zipcode must be the same as the sale zipcode").
 */
class EnderecoMascaradoTest extends TestCase
{
    use RefreshDatabase;

    private function pedidoMascarado(): Order
    {
        return Order::create([
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_MERCADO_LIVRE,
            'external_order_id' => '2000019349636972',
            'shipping_name' => 'Cliente',
            'buyer_document' => '12345678909',
            'shipping_phone' => '11999999999',
            'shipping_zip' => 'XXXXXXX',
            'shipping_street' => 'XXXXXXX',
            'shipping_number' => 'XXXXXXX',
            'shipping_complement' => 'XXXXXXX',
            'shipping_neighborhood' => 'Iririú',
            'shipping_city' => 'Joinville',
            'shipping_state' => 'SC',
            'subtotal' => 50,
            'total' => 50,
        ]);
    }

    private function canalDevolve(array $endereco): void
    {
        $driver = Mockery::mock(MarketplaceChannelDriver::class);
        $driver->shouldReceive('importOrder')->andReturn($endereco);
        $manager = Mockery::mock(MarketplaceDriverManager::class);
        $manager->shouldReceive('driver')->andReturn($driver);
        $this->app->instance(MarketplaceDriverManager::class, $manager);
    }

    private function enderecoReal(): array
    {
        return [
            'shipping_zip' => '89227490',
            'shipping_street' => 'Rua Érico Herhaus',
            'shipping_number' => '193',
            'shipping_complement' => null,
            'shipping_neighborhood' => 'Iririú',
            'shipping_city' => 'Joinville',
            'shipping_state' => 'SC',
        ];
    }

    public function test_reconhece_endereco_mascarado(): void
    {
        $this->assertTrue($this->pedidoMascarado()->enderecoMascarado());
        $this->assertFalse((new Order($this->enderecoReal()))->enderecoMascarado());
    }

    public function test_nova_busca_troca_o_endereco_mascarado_pelo_real(): void
    {
        $this->canalDevolve($this->enderecoReal());
        $order = $this->pedidoMascarado();

        app(OrderImportService::class)->refreshBuyerInfo($order);

        $order->refresh();
        $this->assertSame('89227490', $order->shipping_zip);
        $this->assertSame('Rua Érico Herhaus', $order->shipping_street);
        $this->assertSame('193', $order->shipping_number);
        $this->assertNull($order->shipping_complement);
    }

    public function test_canal_ainda_mascarado_nao_troca_nada(): void
    {
        $this->canalDevolve(['shipping_zip' => 'XXXXXXX', 'shipping_street' => 'XXXXXXX'] + $this->enderecoReal());
        $order = $this->pedidoMascarado();

        app(OrderImportService::class)->refreshBuyerInfo($order);

        $this->assertTrue($order->refresh()->enderecoMascarado());
    }

    public function test_job_nao_emite_nota_com_endereco_mascarado_e_tenta_de_novo(): void
    {
        Queue::fake();
        $full = Mockery::mock(MercadoLivreFull::class);
        $full->shouldReceive('ehFull')->andReturnFalse();
        $this->app->instance(MercadoLivreFull::class, $full);
        $orderImport = Mockery::mock(OrderImportService::class);
        $orderImport->shouldReceive('refreshBuyerInfo')->once();
        $invoices = Mockery::mock(InvoiceService::class);
        $invoices->shouldNotReceive('issue');

        $order = $this->pedidoMascarado();
        (new GenerateInvoiceJob($order->id))->handle($invoices, new OrderFulfillmentTimeline(), $orderImport);

        $this->assertSame(0, Invoice::query()->count());
        $this->assertStringContainsString('XXXXXXX', (string) OrderFulfillmentEvent::query()->where('order_id', $order->id)->latest('id')->value('message'));
        Queue::assertPushedOn('nfe', CallQueuedClosure::class);
    }

    private function pedidoComEndereco(array $endereco): Order
    {
        $order = $this->pedidoMascarado();
        $order->update($endereco);

        return $order->refresh();
    }

    /** Comprador trocou o endereço depois da importação: a nota usa o atual do ML. */
    public function test_ml_endereco_trocado_no_canal_atualiza_antes_da_nota(): void
    {
        $atual = [
            'shipping_zip' => '74430310',
            'shipping_street' => 'Rua Leão XIII',
            'shipping_number' => '238',
            'shipping_complement' => null,
            'shipping_neighborhood' => 'Setor Oeste',
            'shipping_city' => 'Goiânia',
            'shipping_state' => 'GO',
        ];
        $this->canalDevolve($atual);
        $order = $this->pedidoComEndereco($this->enderecoReal());

        app(OrderImportService::class)->refreshBuyerInfo($order);

        $order->refresh();
        $this->assertSame('74430310', $order->shipping_zip);
        $this->assertSame('Rua Leão XIII', $order->shipping_street);
        $this->assertSame('GO', $order->shipping_state);
    }

    /** /shipments fora do ar devolve o fallback: não estraga o endereço bom. */
    public function test_ml_fallback_nao_informado_nao_sobrescreve_endereco_bom(): void
    {
        $this->canalDevolve([
            'shipping_zip' => '00000000',
            'shipping_street' => 'Não informado',
            'shipping_number' => 'S/N',
            'shipping_complement' => null,
            'shipping_neighborhood' => 'Não informado',
            'shipping_city' => 'Não informado',
            'shipping_state' => 'NA',
        ]);
        $order = $this->pedidoComEndereco($this->enderecoReal());

        app(OrderImportService::class)->refreshBuyerInfo($order);

        $this->assertSame('89227490', $order->refresh()->shipping_zip);
    }

    public function test_cep_zerado_ou_rua_nao_informada_nao_serve_para_nota(): void
    {
        $this->assertTrue((new Order(['shipping_zip' => '00000000'] + $this->enderecoReal()))->enderecoIncompletoParaNota());
        $this->assertTrue((new Order(['shipping_zip' => ''] + $this->enderecoReal()))->enderecoIncompletoParaNota());
        $this->assertTrue((new Order(['shipping_street' => 'Não informado'] + $this->enderecoReal()))->enderecoIncompletoParaNota());
        $this->assertFalse((new Order($this->enderecoReal()))->enderecoIncompletoParaNota());
    }

    public function test_job_ml_nao_emite_com_cep_zerado(): void
    {
        Queue::fake();
        $full = Mockery::mock(MercadoLivreFull::class);
        $full->shouldReceive('ehFull')->andReturnFalse();
        $this->app->instance(MercadoLivreFull::class, $full);
        $orderImport = Mockery::mock(OrderImportService::class);
        $orderImport->shouldReceive('refreshBuyerInfo')->once();
        $invoices = Mockery::mock(InvoiceService::class);
        $invoices->shouldNotReceive('issue');

        $order = $this->pedidoComEndereco(['shipping_zip' => '00000000', 'shipping_street' => 'Não informado', 'shipping_number' => 'S/N', 'shipping_complement' => null]);
        (new GenerateInvoiceJob($order->id))->handle($invoices, new OrderFulfillmentTimeline(), $orderImport);

        $this->assertSame(0, Invoice::query()->count());
        Queue::assertPushedOn('nfe', CallQueuedClosure::class);
    }
}
