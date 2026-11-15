<?php

namespace Tests\Feature\Marketplace;

use App\Jobs\ConfirmAmazonShipment;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Drivers\AmazonDriver;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\AmazonDbaShipping;
use App\Modules\Marketplace\Support\CorreiosAutoShipping;
use App\Modules\Marketplace\Support\PagamentoAmazon;
use App\Services\Amazon\Exceptions\AmazonException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Pedido #4186 (15/11/2026): a Amazon recusou o DBA ("The operation is not
 * supported for the given order") e o pedido ficou 7 horas parado, 167
 * tentativas. Agora vai sozinho pelo contrato dos Correios.
 */
class AmazonSemDbaVaiCorreiosTest extends TestCase
{
    use RefreshDatabase;

    private function driver(): AmazonDriver
    {
        $driver = Mockery::mock(AmazonDriver::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $driver->shouldReceive('ensureConfigured')->andReturn(new MarketplaceAccount);

        return $driver;
    }

    private function pedido(): Order
    {
        $pagamento = Mockery::mock(PagamentoAmazon::class);
        $pagamento->shouldReceive('segurarExpedicao')->andReturnFalse();
        $this->app->instance(PagamentoAmazon::class, $pagamento);

        return Order::create([
            'status' => Order::STATUS_PAID, 'origin' => Order::ORIGIN_AMAZON, 'external_order_id' => '702-2647832-6009069',
            'shipping_name' => 'Cliente', 'shipping_phone' => '1', 'shipping_zip' => '90010240', 'shipping_street' => 'Rua',
            'shipping_number' => '1', 'shipping_neighborhood' => 'Centro', 'shipping_city' => 'Porto Alegre', 'shipping_state' => 'RS',
            'subtotal' => 35.69, 'total' => 35.69,
        ]);
    }

    public function test_dba_recusado_gera_pelos_correios_e_confirma_na_amazon(): void
    {
        Queue::fake();
        $order = $this->pedido();

        $dba = Mockery::mock(AmazonDbaShipping::class);
        $dba->shouldReceive('agendar')->once()->andThrow(new AmazonException('The operation is not supported for the given order in marketplace A2Q3Y263D00KWC (shipFrom: BR, shipTo: BR)'));
        $this->app->instance(AmazonDbaShipping::class, $dba);

        $pp = new CorreiosPrePostagem(['correios_id' => 'PP1', 'codigo_objeto' => 'AP123456789BR', 'service_label' => 'PAC']);
        $correios = Mockery::mock(CorreiosAutoShipping::class);
        $correios->shouldReceive('geradaPara')->andReturnNull();
        $correios->shouldReceive('confirm')->once()->andReturn($pp);
        $this->app->instance(CorreiosAutoShipping::class, $correios);

        $envio = $this->driver()->confirmShipping($order);

        $this->assertSame('AP123456789BR', $envio['tracking_code']);
        $this->assertStringStartsWith('Correios PAC', $envio['shipping_method']);
        Queue::assertPushed(ConfirmAmazonShipment::class, fn ($job) => $job->orderId === $order->id);
    }

    public function test_outro_erro_do_dba_nao_troca_de_transportadora(): void
    {
        Queue::fake();
        $order = $this->pedido();

        $dba = Mockery::mock(AmazonDbaShipping::class);
        $dba->shouldReceive('agendar')->andThrow(new RuntimeException('Sem horário de Ponto de Envio disponível'));
        $this->app->instance(AmazonDbaShipping::class, $dba);

        $correios = Mockery::mock(CorreiosAutoShipping::class);
        $correios->shouldReceive('geradaPara')->andReturnNull();
        $correios->shouldNotReceive('confirm');
        $this->app->instance(CorreiosAutoShipping::class, $correios);

        $this->expectException(RuntimeException::class);
        $this->driver()->confirmShipping($order);
    }

    public function test_pedido_que_ja_foi_pelos_correios_nao_tenta_o_dba_de_novo(): void
    {
        Queue::fake();
        $order = $this->pedido();

        $dba = Mockery::mock(AmazonDbaShipping::class);
        $dba->shouldNotReceive('agendar');
        $this->app->instance(AmazonDbaShipping::class, $dba);

        $correios = Mockery::mock(CorreiosAutoShipping::class);
        $correios->shouldReceive('geradaPara')->andReturn(new CorreiosPrePostagem(['correios_id' => 'PP1', 'codigo_objeto' => 'AP1BR', 'service_label' => 'PAC']));
        $this->app->instance(CorreiosAutoShipping::class, $correios);

        $this->assertSame('AP1BR', $this->driver()->confirmShipping($order)['tracking_code']);
    }
}
