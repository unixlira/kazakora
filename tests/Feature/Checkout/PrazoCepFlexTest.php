<?php

namespace Tests\Feature\Checkout;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Services\CorreiosFreightQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/** Prazo pelo CEP na página do produto (pedido 2026-10-10): Full, Flex (até 3 dias) ou sem selo. */
class PrazoCepFlexTest extends TestCase
{
    use RefreshDatabase;

    private function correios(array $dias): void
    {
        $mock = Mockery::mock(CorreiosFreightQuoteService::class);
        $mock->shouldReceive('quote')->andReturn(collect($dias)->map(fn ($d) => ['estimated_days' => $d])->all());
        $this->app->instance(CorreiosFreightQuoteService::class, $mock);
    }

    public function test_flex_quando_chega_em_ate_3_dias_e_nada_quando_passa(): void
    {
        $product = Product::factory()->create(['is_active' => true]);

        $this->correios([5, 2]);
        $this->getJson("/frete/prazo?cep=13990-000&produto={$product->id}")->assertJson(['prazo_dias' => 2, 'modalidade' => 'flex']);

        $this->correios([6, 9]);
        $this->getJson("/frete/prazo?cep=69900-000&produto={$product->id}")->assertJson(['prazo_dias' => 6, 'modalidade' => null]);
    }
}
