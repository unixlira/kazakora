<?php

namespace Tests\Feature\Push;

use App\Models\User;
use App\Modules\Admin\Support\Publicidade\CampanhasMetaPorLoja;
use App\Modules\Admin\Support\Publicidade\PlataformaDeAnuncio;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelAdSpend;
use App\Modules\Marketplace\Models\OrderChannelFee;
use App\Modules\Notifications\Push\TokensDoApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Aba Campanhas dos apps de vendas, bloco "resultado" (pedido do Lira em
 * 2026-11-21): gasto da Meta × faturamento bruto × valor líquido da loja do
 * app, na mesma janela do gasto.
 */
class ResultadoCampanhasAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-19 15:00:00'));
        Cache::flush();
        config(['loja.simples.aliquota' => 10, 'loja.simples.desde' => '2026-09-01']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function api(string $app): static
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        return $this->withToken(TokensDoApp::emitir($admin, $app)['token']);
    }

    /** Meta só da KazaKora; gasto por date_preset. */
    private function metaDaKazaKora(array $gastoPorPreset, ?string $erro = null): void
    {
        $this->mock(CampanhasMetaPorLoja::class, function (MockInterface $m) use ($gastoPorPreset, $erro) {
            $m->shouldReceive('lojas')->andReturn([CampanhasMetaPorLoja::KAZAKORA => 'KazaKora']);
            $m->shouldReceive('montar')->andReturnUsing(fn (string $loja, string $preset) => [[
                'loja' => $loja,
                'erro' => $erro,
                'totais' => $erro ? null : ['gasto' => $gastoPorPreset[$preset] ?? 0, 'compras' => 1, 'vendas' => 100, 'roas' => 1],
                'campanhas' => [],
            ]]);
        });
    }

    private function pedido(string $origin, ?string $site, string $status, float $subtotal, float $frete, float $custo, float $taxa, string $quando): Order
    {
        $order = Order::create([
            'user_id' => User::factory()->create()->id,
            'origin' => $origin,
            'site_origem' => $site,
            'external_order_id' => 'PED-'.uniqid(),
            'status' => $status,
            'shipping_name' => 'Cliente',
            'shipping_phone' => '11988887777',
            'shipping_zip' => '01310-100',
            'shipping_street' => 'Rua A',
            'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => $subtotal,
            'shipping_cost' => $frete,
            'total' => $subtotal + $frete,
        ]);
        $order->forceFill(['created_at' => Carbon::parse($quando)])->save();
        $order->items()->create(['product_name' => 'Item', 'product_price' => $subtotal, 'quantity' => 1, 'subtotal' => $subtotal, 'manual_cost_price' => $custo]);
        if ($taxa > 0) {
            OrderChannelFee::create(['order_id' => $order->id, 'channel' => $origin, 'gross_amount' => $subtotal, 'fee_amount' => $taxa, 'source' => 'report', 'computed_at' => now()]);
        }

        return $order;
    }

    private function cenario(): void
    {
        // KazaKora (site): hoje e no dia 15; cancelado e outubro ficam fora.
        $this->pedido(Order::ORIGIN_STORE, null, Order::STATUS_PAID, 100, 10, 40, 5, '2026-11-19 09:00');
        $this->pedido(Order::ORIGIN_STORE, null, Order::STATUS_SHIPPED, 200, 0, 80, 10, '2026-11-15 11:00');
        $this->pedido(Order::ORIGIN_STORE, null, Order::STATUS_CANCELLED, 70, 0, 10, 0, '2026-11-19 10:00');
        $this->pedido(Order::ORIGIN_STORE, null, Order::STATUS_PAID, 500, 0, 100, 0, '2026-10-20 10:00');
        // Outras lojas e marketplace: nunca entram na KazaKora.
        $this->pedido(Order::ORIGIN_STORE, Order::SITE_FERRAKORA, Order::STATUS_PAID, 300, 0, 100, 0, '2026-11-15 10:00');
        $this->pedido(Order::ORIGIN_MERCADO_LIVRE, null, Order::STATUS_PAID, 999, 0, 0, 0, '2026-11-15 10:00');
        // Meta já gravada na conta oficial (canal loja): não pode sair duas vezes.
        ChannelAdSpend::create(['channel' => PlataformaDeAnuncio::META, 'date' => '2026-11-10', 'spend' => 50,
            'impressions' => 0, 'clicks' => 0, 'attributed_orders' => 0, 'attributed_gmv' => 0]);
    }

    public function test_mes_da_kazakora_bate_com_a_conta_oficial_sem_descontar_o_anuncio_duas_vezes(): void
    {
        $this->cenario();
        $this->metaDaKazaKora(['this_month' => 60]);

        $r = $this->api('kazakora')->getJson('/api-apps-vendas/kazakora/campanhas?periodo=mes')->assertOk();

        $r->assertJsonPath('totais.gasto', 60)
            ->assertJsonPath('resultado.de', '2026-11-01')
            ->assertJsonPath('resultado.ate', '2026-11-19')
            ->assertJsonPath('resultado.pedidos', 2)
            ->assertJsonPath('resultado.gasto_meta', 60)
            ->assertJsonPath('resultado.faturamento_bruto', 310)  // 110 + 200 (com frete)
            ->assertJsonPath('resultado.receita', 300)            // sem o frete do cliente
            ->assertJsonPath('resultado.custo_produtos', 120)
            ->assertJsonPath('resultado.taxas', 15)
            ->assertJsonPath('resultado.imposto', 30)             // 10% de 300
            ->assertJsonPath('resultado.anuncios_na_conta', 50)
            ->assertJsonPath('resultado.valor_liquido', 135)      // 300 − 120 − 15 − 30
            ->assertJsonPath('resultado.sobra_depois_do_anuncio', 75) // 135 − 60
            ->assertJsonPath('resultado.roas_real', 5.17);        // 310 ÷ 60
    }

    public function test_hoje_e_semana_usam_a_mesma_janela_da_meta(): void
    {
        $this->cenario();
        $this->metaDaKazaKora(['today' => 20, 'last_7d' => 0]);

        $this->api('kazakora')->getJson('/api-apps-vendas/kazakora/campanhas?periodo=hoje')->assertOk()
            ->assertJsonPath('resultado.de', '2026-11-19')
            ->assertJsonPath('resultado.ate', '2026-11-19')
            ->assertJsonPath('resultado.faturamento_bruto', 110)
            ->assertJsonPath('resultado.valor_liquido', 45)       // 100 − 40 − 5 − 10
            ->assertJsonPath('resultado.sobra_depois_do_anuncio', 25)
            ->assertJsonPath('resultado.roas_real', 5.5);

        // last_7d da Meta = 12 a 18/11 (hoje fora). Sem gasto: 0, ROAS nulo.
        $this->api('kazakora')->getJson('/api-apps-vendas/kazakora/campanhas?periodo=7d')->assertOk()
            ->assertJsonPath('resultado.de', '2026-11-12')
            ->assertJsonPath('resultado.ate', '2026-11-18')
            ->assertJsonPath('resultado.faturamento_bruto', 200)
            ->assertJsonPath('resultado.gasto_meta', 0)
            ->assertJsonPath('resultado.valor_liquido', 90)       // 200 − 80 − 10 − 20
            ->assertJsonPath('resultado.sobra_depois_do_anuncio', 90)
            ->assertJsonPath('resultado.roas_real', null);
    }

    public function test_ferrakora_sem_meta_mostra_so_a_loja_dela_com_gasto_zero(): void
    {
        $this->cenario();
        $this->metaDaKazaKora(['this_month' => 60]);

        $this->api('ferrakora')->getJson('/api-apps-vendas/ferrakora/campanhas?periodo=mes')->assertOk()
            ->assertJsonPath('configurada', false)
            ->assertJsonPath('resultado.pedidos', 1)
            ->assertJsonPath('resultado.gasto_meta', 0)
            ->assertJsonPath('resultado.faturamento_bruto', 300)
            ->assertJsonPath('resultado.anuncios_na_conta', 0)
            ->assertJsonPath('resultado.valor_liquido', 170)      // 300 − 100 − 30
            ->assertJsonPath('resultado.sobra_depois_do_anuncio', 170)
            ->assertJsonPath('resultado.roas_real', null);
    }

    public function test_meta_com_erro_nao_inventa_sobra(): void
    {
        $this->cenario();
        $this->metaDaKazaKora([], 'Limite de chamadas');

        $this->api('kazakora')->getJson('/api-apps-vendas/kazakora/campanhas?periodo=mes')->assertOk()
            ->assertJsonPath('erro', 'Limite de chamadas')
            ->assertJsonPath('resultado.meta_erro', 'Limite de chamadas')
            ->assertJsonPath('resultado.gasto_meta', null)
            ->assertJsonPath('resultado.faturamento_bruto', 310)
            ->assertJsonPath('resultado.valor_liquido', 135)
            ->assertJsonPath('resultado.sobra_depois_do_anuncio', null)
            ->assertJsonPath('resultado.roas_real', null);
    }

    public function test_loja_sem_venda_nem_meta_devolve_zeros(): void
    {
        $this->api('kazakora')->getJson('/api-apps-vendas/kazakora/campanhas')->assertOk()
            ->assertJsonPath('configurada', false)
            ->assertJsonPath('resultado.gasto_meta', 0)
            ->assertJsonPath('resultado.faturamento_bruto', 0)
            ->assertJsonPath('resultado.valor_liquido', 0)
            ->assertJsonPath('resultado.sobra_depois_do_anuncio', 0)
            ->assertJsonPath('resultado.roas_real', null);
    }
}
