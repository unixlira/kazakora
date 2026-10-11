<?php

namespace Tests\Feature\Marketplace;

use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Support\ContributionMargin;
use App\Services\Correios\CorreiosPrePostagemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Pedido explícito 2026-09-25: a pré-postagem que sobrou de um pedido
 * despachado à mão (#2485, AP538309458BR) é cancelada nos Correios pela
 * API — e some da etiqueta do pedido e do custo de frete.
 */
class CancelCorreiosPrePostagemTest extends TestCase
{
    use RefreshDatabase;

    private function prePostagem(string $status = CorreiosPrePostagem::STATUS_GERADA): CorreiosPrePostagem
    {
        return CorreiosPrePostagem::create([
            'order_id' => null, 'origin' => 'amazon', 'customer_name' => 'Maria', 'zip' => '13010000', 'street' => 'Rua',
            'number' => '1', 'neighborhood' => 'Centro', 'city' => 'Campinas', 'state' => 'SP', 'service_code' => '03298',
            'service_label' => 'PAC (contrato)', 'postage_price' => 22.75, 'weight_grams' => 400, 'dimension_format' => '2',
            'content_items' => [], 'status' => $status, 'correios_id' => 'PRX123', 'codigo_objeto' => 'AP538309458BR',
        ]);
    }

    public function test_cancels_at_correios_by_object_code_and_marks_it_cancelled(): void
    {
        $prePostagem = $this->prePostagem();

        $correios = Mockery::mock(CorreiosPrePostagemService::class);
        $correios->shouldReceive('cancel')->once()->with('PRX123');
        $this->app->instance(CorreiosPrePostagemService::class, $correios);

        $this->artisan('correios:cancelar', ['codigo' => 'AP538309458BR'])->assertSuccessful();

        $this->assertSame(CorreiosPrePostagem::STATUS_CANCELADA, $prePostagem->fresh()->status);
    }

    public function test_refuses_what_is_not_a_generated_pre_postagem(): void
    {
        $this->prePostagem(CorreiosPrePostagem::STATUS_ERRO);

        $correios = Mockery::mock(CorreiosPrePostagemService::class);
        $correios->shouldNotReceive('cancel');
        $this->app->instance(CorreiosPrePostagemService::class, $correios);

        $this->artisan('correios:cancelar', ['codigo' => 'AP538309458BR'])->assertFailed();
    }

    public function test_cancelled_postage_is_not_a_freight_cost_anymore(): void
    {
        $order = \App\Modules\Checkout\Models\Order::create([
            'status' => \App\Modules\Checkout\Models\Order::STATUS_PAID, 'origin' => 'amazon', 'external_order_id' => '701-8504193-2769044',
            'shipping_name' => 'Maria', 'shipping_phone' => '1', 'shipping_zip' => '13010000', 'shipping_street' => 'Rua',
            'shipping_number' => '1', 'shipping_neighborhood' => 'Centro', 'shipping_city' => 'Campinas', 'shipping_state' => 'SP',
            'subtotal' => 100, 'total' => 100,
        ]);
        $prePostagem = $this->prePostagem();
        $prePostagem->update(['order_id' => $order->id]);

        $this->assertSame(22.75, ContributionMargin::correios(null));

        $correios = Mockery::mock(CorreiosPrePostagemService::class);
        $correios->shouldReceive('cancel')->once();
        $this->app->instance(CorreiosPrePostagemService::class, $correios);
        app(\App\Modules\Marketplace\Support\CorreiosCancelamento::class)->cancelar($prePostagem);

        $this->assertSame(0.0, ContributionMargin::correios(null));
        $this->assertNull(app(\App\Modules\Marketplace\Support\CorreiosAutoShipping::class)->geradaPara($order));
    }
}
