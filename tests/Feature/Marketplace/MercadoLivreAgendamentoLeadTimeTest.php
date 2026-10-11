<?php

namespace Tests\Feature\Marketplace;

use App\Modules\Marketplace\Drivers\MercadoLivreDriver;
use App\Services\MercadoLivre\Services\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 2026-10-11 (#3018): o ML passou a mandar a data do agendamento só em
 * /shipments/{id}/lead_time; sem ela a venda agendada perdia os retries.
 */
class MercadoLivreAgendamentoLeadTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_data_do_agendamento_vem_do_lead_time(): void
    {
        $shipments = Mockery::mock(ShipmentService::class);
        $shipments->shouldReceive('getLeadTime')->with('48231617416')->andReturn(['buffering' => ['date' => now()->addDays(3)->startOfDay()->toIso8601String()]]);
        $this->app->instance(ShipmentService::class, $shipments);

        $driver = app(MercadoLivreDriver::class);
        $metodo = new ReflectionMethod($driver, 'extractScheduledFor');

        $data = $metodo->invoke($driver, ['id' => 48231617416, 'status' => 'pending', 'substatus' => 'buffered', 'shipping_option' => []]);

        $this->assertNotNull($data);
        $this->assertTrue($data->isFuture());
    }
}
