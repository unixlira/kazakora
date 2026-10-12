<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelAdSpend;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Models\OrderChannelFee;
use App\Modules\Marketplace\Support\ContributionMargin;
use App\Modules\Marketplace\Support\CurvaAbc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Menu Estatísticas (pedido do Lira 2026-10-12): curva ABC por marketplace
 * (Mercado Livre / Shopee) com lucro pela conta oficial, variações juntas e
 * tendência contra o período anterior.
 *
 * Valores com centavos de propósito: json_encode(100.0) vira "100" e o
 * ->where() do Inertia compara estrito.
 */
class EstatisticasCurvaAbcTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-12 15:00:00', 'America/Sao_Paulo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    /** @param list<array{0: Product|null, 1: int, 2: float}> $itens */
    private function pedido(string $origem, array $itens, array $pedido = []): Order
    {
        $subtotal = array_sum(array_map(fn ($i) => $i[1] * $i[2], $itens));
        $order = Order::create(array_merge([
            'status' => Order::STATUS_PAID, 'origin' => $origem,
            'shipping_name' => 'Cliente', 'shipping_phone' => '11999999999', 'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => $subtotal, 'total' => $subtotal,
        ], $pedido));
        // created_at e estornado_em não são preenchíveis em massa.
        $order->forceFill(array_intersect_key($pedido, array_flip(['created_at', 'estornado_em'])))->save();
        foreach ($itens as [$produto, $quantidade, $preco]) {
            $order->items()->create([
                'product_id' => $produto?->id, 'product_name' => $produto?->name ?? 'Avulso', 'product_price' => $preco,
                'quantity' => $quantidade, 'subtotal' => $quantidade * $preco,
            ]);
        }

        return $order;
    }

    public function test_curva_do_mercado_livre_classifica_junta_variacoes_e_filtra_canal_e_venda_efetiva(): void
    {
        // Variações pelo produto pai.
        $pai = Product::factory()->create(['name' => 'Power Bank 10000mah', 'cost_price' => 20]);
        $rosa = Product::factory()->create(['name' => 'Power Bank 10000mah Rosa', 'color' => 'Rosa', 'parent_product_id' => $pai->id, 'cost_price' => 20]);
        $verde = Product::factory()->create(['name' => 'Power Bank 10000mah Verde', 'color' => 'Verde', 'parent_product_id' => $pai->id, 'cost_price' => 20]);
        // Variações sem pai: mesmo nome com a cor no fim.
        $fonteBranca = Product::factory()->create(['name' => 'Fonte Pet Branco', 'color' => 'Branco', 'cost_price' => 10]);
        $fontePreta = Product::factory()->create(['name' => 'Fonte Pet Preto', 'color' => 'Preto', 'cost_price' => 10]);
        $lanterna = Product::factory()->create(['name' => 'Lanterna', 'cost_price' => 5]);
        $tabua = Product::factory()->create(['name' => 'Tabua', 'cost_price' => 2]);

        // Faturamento ML: power bank 700,50 (70%), fonte 200 (90%), lanterna 60 (96%), tábua 40 (100%).
        $this->pedido(Order::ORIGIN_MERCADO_LIVRE, [[$rosa, 1, 400.50], [$verde, 1, 300]]);
        $this->pedido(Order::ORIGIN_MERCADO_LIVRE, [[$fonteBranca, 1, 120]]);
        $this->pedido(Order::ORIGIN_MERCADO_LIVRE, [[$fontePreta, 1, 80]]);
        $this->pedido(Order::ORIGIN_MERCADO_LIVRE, [[$lanterna, 3, 20]]);
        $this->pedido(Order::ORIGIN_MERCADO_LIVRE, [[$tabua, 4, 10]]);
        // Fora da curva: outro canal, cancelado, estornado, nota avulsa e venda antiga.
        $this->pedido(Order::ORIGIN_SHOPEE, [[$tabua, 100, 10]]);
        $this->pedido(Order::ORIGIN_MERCADO_LIVRE, [[$tabua, 100, 10]], ['status' => Order::STATUS_CANCELLED]);
        $this->pedido(Order::ORIGIN_MERCADO_LIVRE, [[$tabua, 100, 10]], ['estornado_em' => now()]);
        $this->pedido(Order::ORIGIN_MANUAL_INVOICE, [[$tabua, 100, 10]]);
        $this->pedido(Order::ORIGIN_MERCADO_LIVRE, [[$tabua, 100, 10]], ['created_at' => now()->subDays(40)]);

        $response = $this->actingAs($this->admin())->get('/admin/estatisticas/curva-abc/mercado-livre');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Estatisticas/CurvaAbc', false)
            ->where('canal.nome', 'Mercado Livre')
            ->where('filtros.de', '2026-09-13')
            ->where('filtros.ate', '2026-10-12')
            ->where('curva.total', 1000.5)
            ->where('curva.unidades', 11)
            ->where('curva.produtos', fn ($produtos) => collect($produtos)->map(fn ($p) => [$p['nome'], $p['classe']])->all() === [
                ['Power Bank 10000mah', 'A'], ['Fonte Pet', 'A'], ['Lanterna', 'B'], ['Tabua', 'C'],
            ])
            ->where('curva.produtos.0.variacoes', ['Rosa', 'Verde'])
            ->where('curva.produtos.0.id', $pai->id)
            ->where('curva.produtos.0.unidades', 2)
            ->where('curva.produtos.1.variacoes', ['Branco', 'Preto'])
            ->where('curva.produtos.1.acumulado', 90)
            ->where('curva.produtos.3.unidades', 4)
            ->where('curva.classes.A.produtos', 2)
            ->where('curva.classes.C.receita', 40));
    }

    public function test_lucro_por_produto_fecha_com_a_conta_oficial_do_canal(): void
    {
        $caneca = Product::factory()->create(['name' => 'Caneca', 'cost_price' => 12.30]);
        $prato = Product::factory()->create(['name' => 'Prato', 'cost_price' => 7.45]);

        $pedido = $this->pedido(Order::ORIGIN_SHOPEE, [[$caneca, 2, 39.90], [$prato, 1, 19.90]]);
        OrderChannelFee::create(['order_id' => $pedido->id, 'channel' => 'shopee', 'gross_amount' => 99.70, 'fee_amount' => 20.10, 'computed_at' => now()]);
        CorreiosPrePostagem::create(['order_id' => $pedido->id, 'status' => CorreiosPrePostagem::STATUS_GERADA, 'postage_price' => 15.55] + $this->prePostagemExtra());
        $outro = $this->pedido(Order::ORIGIN_SHOPEE, [[$prato, 3, 19.90]]);
        OrderChannelFee::create(['order_id' => $outro->id, 'channel' => 'shopee', 'gross_amount' => 59.70, 'fee_amount' => 11.94, 'computed_at' => now()]);
        ChannelAdSpend::create(['date' => now()->subDays(2)->toDateString(), 'channel' => 'shopee', 'spend' => 8.75]);
        ChannelAdSpend::create(['date' => now()->subDays(2)->toDateString(), 'channel' => 'mercado_livre', 'spend' => 99.99]);

        $desde = Carbon::today()->subDays(29);
        $ate = Carbon::tomorrow();
        $curva = app(CurvaAbc::class)->analisar($desde, $ate, Order::ORIGIN_SHOPEE);
        $oficial = app(ContributionMargin::class)->porCanal($desde, $ate)[Order::ORIGIN_SHOPEE];

        $this->assertSame($oficial['receita'], $curva['total']);
        $this->assertSame($oficial['ads'], $curva['ads']);
        $this->assertEqualsWithDelta($oficial['margem'], $curva['lucro'], 0.011);

        // Caneca: 79,80 de 99,70 do pedido leva 80,04% da taxa e do frete.
        $canecaLinha = collect($curva['produtos'])->firstWhere('nome', 'Caneca');
        $peso = 79.80 / 99.70;
        $this->assertEqualsWithDelta(79.80 - 24.60 - 20.10 * $peso - 15.55 * $peso, $canecaLinha['lucro'], 0.011);

        $this->actingAs($this->admin())->get('/admin/estatisticas/curva-abc/shopee')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('canal.nome', 'Shopee')
                ->where('curva.ads', 8.75)
                ->where('curva.lucro', $curva['lucro']));
    }

    public function test_tendencia_compara_com_o_periodo_anterior_de_mesmo_tamanho(): void
    {
        $sobe = Product::factory()->create(['name' => 'Sobe']);
        $novo = Product::factory()->create(['name' => 'Novo']);

        $this->pedido(Order::ORIGIN_SHOPEE, [[$sobe, 3, 50]], ['created_at' => Carbon::parse('2026-10-05 10:00')]);
        $this->pedido(Order::ORIGIN_SHOPEE, [[$novo, 1, 25.50]], ['created_at' => Carbon::parse('2026-10-06 10:00')]);
        // Período anterior de 7 dias: 2026-09-26 a 2026-10-02.
        $this->pedido(Order::ORIGIN_SHOPEE, [[$sobe, 2, 50]], ['created_at' => Carbon::parse('2026-09-30 10:00')]);
        // Antes disso não conta.
        $this->pedido(Order::ORIGIN_SHOPEE, [[$novo, 9, 50]], ['created_at' => Carbon::parse('2026-09-20 10:00')]);

        $this->actingAs($this->admin())->get('/admin/estatisticas/curva-abc/shopee?de=2026-10-03&ate=2026-10-09')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filtros.de', '2026-10-03')
                ->where('filtros.ate', '2026-10-09')
                ->where('curva.anterior.desde', '2026-09-26')
                ->where('curva.anterior.ate', '2026-10-02')
                ->where('curva.produtos.0.nome', 'Sobe')
                ->where('curva.produtos.0.tendencia', 50)
                ->where('curva.produtos.1.nome', 'Novo')
                ->where('curva.produtos.1.tendencia', null));
    }

    public function test_rotas_canal_desconhecido_datas_invalidas_e_acesso(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/estatisticas/curva-abc/tiktok')->assertNotFound();
        $this->actingAs($admin)->get('/admin/estatisticas/curva-abc/shopee?de=lixo&ate=2026-13-45')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('filtros.de', '2026-09-13')->where('filtros.ate', '2026-10-12')->where('curva.produtos', []));
        // Datas invertidas trocam de lugar.
        $this->actingAs($admin)->get('/admin/estatisticas/curva-abc/mercado-livre?de=2026-10-10&ate=2026-10-01')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('filtros.de', '2026-10-01')->where('filtros.ate', '2026-10-10'));

        $this->actingAs(User::factory()->create(['role' => User::ROLE_CUSTOMER]))->get('/admin/estatisticas/curva-abc/shopee')->assertForbidden();
        auth()->logout();
        $this->get('/admin/estatisticas/curva-abc/shopee')->assertRedirect();
    }

    /** Campos obrigatórios da pré-postagem que não importam para a conta. */
    private function prePostagemExtra(): array
    {
        return [
            'customer_name' => 'Cliente', 'zip' => '01000000', 'street' => 'Rua X', 'number' => '1',
            'neighborhood' => 'Centro', 'city' => 'São Paulo', 'state' => 'SP',
            'service_code' => '03298', 'weight_grams' => 300, 'content_items' => [],
        ];
    }
}
