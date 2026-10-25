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

    public function test_opcoes_da_tabela_full_flex_padrao_e_turbo_na_ordem(): void
    {
        config(['frete_embutido.folga_dias' => 2, 'frete_embutido.prazo_padrao_dias' => 7, 'entrega_expressa.horario_entrega' => '21h']);

        $this->assertSame(
            [['tipo' => 'full', 'quando' => 'amanha', 'mensagem' => 'Receba amanhã', 'horario' => '21h', 'preco' => 0.0]],
            \App\Modules\Checkout\Support\PrazoPorCep::opcoes(['entrega_expressa' => ['tipo' => 'amanha', 'mensagem' => 'Receba amanhã'], 'prazo_dias' => null], null),
        );

        // No raio do Full o Turbo aparece também, sem o "dias antes" (pedido 2026-10-23).
        $this->assertSame(
            [['tipo' => 'full', 'quando' => 'amanha', 'mensagem' => 'Receba amanhã', 'horario' => '21h', 'preco' => 0.0], ['tipo' => 'turbo', 'ate' => 1, 'dias_a_menos' => null, 'preco' => 9.9]],
            \App\Modules\Checkout\Support\PrazoPorCep::opcoes(['entrega_expressa' => ['tipo' => 'amanha', 'mensagem' => 'Receba amanhã'], 'prazo_dias' => null], ['prazo' => 3, 'turbo' => ['preco' => 9.9, 'prazo' => 1, 'dias_a_menos' => 2]]),
        );

        // Flex: até Y + 2, geralmente Y (prazo do grátis, o mesmo do checkout), e o Turbo depois.
        $opcoes = \App\Modules\Checkout\Support\PrazoPorCep::opcoes(
            ['entrega_expressa' => null, 'prazo_dias' => 2],
            ['prazo' => 3, 'turbo' => ['preco' => 12.5, 'prazo' => 2, 'dias_a_menos' => 1]],
        );
        $this->assertSame(['flex', 'turbo'], array_column($opcoes, 'tipo'));
        $this->assertSame(['ate' => 5, 'geralmente' => 3], ['ate' => $opcoes[0]['ate'], 'geralmente' => $opcoes[0]['geralmente']]);
        $this->assertSame(12.5, $opcoes[1]['preco']);

        // Mais de 3 dias: Padrão; sem prazo nenhum, até 7 sem o "geralmente".
        $this->assertSame('padrao', \App\Modules\Checkout\Support\PrazoPorCep::opcoes(['entrega_expressa' => null, 'prazo_dias' => 2], ['prazo' => 6, 'turbo' => null])[0]['tipo']);
        // Correios ou transportadora por último, depois do Turbo.
        $this->assertSame(['turbo', 'padrao'], array_column(\App\Modules\Checkout\Support\PrazoPorCep::opcoes(['entrega_expressa' => null, 'prazo_dias' => 6], ['prazo' => 7, 'turbo' => ['preco' => 28.9, 'prazo' => 3, 'dias_a_menos' => 4]]), 'tipo'));
        $this->assertSame(
            [['tipo' => 'padrao', 'ate' => 7, 'geralmente' => null, 'preco' => 0.0]],
            \App\Modules\Checkout\Support\PrazoPorCep::opcoes(['entrega_expressa' => null, 'prazo_dias' => null], null),
        );
    }

    public function test_pagina_do_produto_recebe_as_opcoes(): void
    {
        $product = Product::factory()->create(['is_active' => true]);
        config(['entrega_expressa.faixas' => [['inicio' => '01000000', 'fim' => '05999999', 'local' => 'São Paulo']]]);

        $this->getJson("/frete/prazo?cep=01310-100&produto={$product->id}")
            ->assertOk()->assertJsonPath('opcoes.0.tipo', 'full')->assertJsonPath('opcoes.1.tipo', 'turbo');
    }

    public function test_fora_da_grande_sp_manda_o_prazo_do_pac_e_dentro_nao(): void
    {
        $product = Product::factory()->create(['is_active' => true]);
        config(['entrega_expressa.faixas' => [], 'frete_embutido.folga_dias' => 2]);

        // Maranhão: grátis pelo PAC com o prazo real do CEP (pedido do Lira 2026-10-24).
        $this->correios([8]);
        $this->getJson("/frete/prazo?cep=65010-000&produto={$product->id}")
            ->assertOk()->assertJsonPath('pac.ate', 10)->assertJsonPath('pac.mensagem', 'Receba em até 10 dias úteis');

        // Grande SP (Guarulhos) segue no Flex, sem PAC.
        $this->correios([1]);
        $this->getJson("/frete/prazo?cep=07010-000&produto={$product->id}")->assertOk()->assertJsonPath('pac', null);
    }

    public function test_motoboy_so_junto_do_full(): void
    {
        $product = Product::factory()->create(['is_active' => true]);
        config([
            'entrega_expressa.faixas' => [['inicio' => '01000000', 'fim' => '05999999', 'local' => 'São Paulo']],
            'entrega_expressa.motoboy.faixas_extra' => [['inicio' => '09300000', 'fim' => '09399999', 'local' => 'Mauá']],
            'entrega_expressa.motoboy.ignorar_horario' => true,
        ]);
        $this->correios([1]);

        $this->getJson("/frete/prazo?cep=01310-100&produto={$product->id}")->assertOk()->assertJsonPath('motoboy.preco', 39);
        // Mauá: Flex grátis sem o motoboy junto (pedido do Lira 2026-10-24).
        $this->getJson("/frete/prazo?cep=09310-000&produto={$product->id}")->assertOk()->assertJsonPath('motoboy', null);
    }
}
