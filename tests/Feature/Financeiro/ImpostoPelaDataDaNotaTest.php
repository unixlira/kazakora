<?php

namespace Tests\Feature\Financeiro;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Support\ContributionMargin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Imposto do Simples pela competência fiscal (12/11/2026): conferido com a
 * guia DAS de outubro/2026 (R$ 2.300,96 = 4% das notas de venda do mês menos
 * as notas de devolução do mês). A base segue a data da NOTA, não a do pedido.
 */
class ImpostoPelaDataDaNotaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['loja.simples.aliquota' => 4, 'loja.simples.desde' => '2026-09-01']);
        Carbon::setTestNow('2026-11-12 15:00:00');
    }

    private function pedido(string $origem, float $valor, string $quando, array $extra = []): Order
    {
        $pedido = Order::create([
            'status' => Order::STATUS_PAID, 'origin' => $origem,
            'shipping_name' => 'Maria', 'shipping_phone' => '11999990000', 'shipping_zip' => '03187040',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Mooca',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => $valor, 'discount_amount' => 0, 'shipping_cost' => 0, 'total' => $valor,
            ...$extra,
        ]);
        $pedido->forceFill(['created_at' => $quando])->save();

        return $pedido;
    }

    private function nota(Order $pedido, float $valor, string $autorizadaEm, array $extra = []): void
    {
        static $numero = 100;
        DB::table('invoices')->insert([
            'order_id' => $pedido->id, 'numero' => $numero++, 'status' => 'authorized', 'operation_type' => 'saida',
            'valor_total' => $valor, 'autorizada_em' => $autorizadaEm, 'created_at' => $autorizadaEm, 'updated_at' => $autorizadaEm,
            ...$extra,
        ]);
    }

    private function outubro(): array
    {
        return [Carbon::parse('2026-10-01'), Carbon::parse('2026-11-01')];
    }

    public function test_nota_de_outubro_de_pedido_de_setembro_e_imposto_de_outubro(): void
    {
        $setembro = $this->pedido('shopee', 100, '2026-09-29 20:00:00');
        $this->nota($setembro, 100, '2026-10-01 09:00:00');
        $outubro = $this->pedido('shopee', 50, '2026-10-31 22:00:00');
        $this->nota($outubro, 50, '2026-11-01 08:00:00');

        $this->assertEqualsWithDelta(100.0, ContributionMargin::baseImposto(...$this->outubro())['shopee'], 0.001);
        $this->assertEqualsWithDelta(4.0, ContributionMargin::imposto(...$this->outubro())['shopee'], 0.001);
        $this->assertSame([], ContributionMargin::baseImposto(Carbon::parse('2026-09-01'), Carbon::parse('2026-10-01')));
        $this->assertEqualsWithDelta(50.0, ContributionMargin::baseImposto(Carbon::parse('2026-11-01'), Carbon::parse('2026-12-01'))['shopee'], 0.001);
    }

    public function test_nota_de_devolucao_abate_no_mes_dela_e_no_canal_da_venda(): void
    {
        $venda = $this->pedido('mercado_livre', 300, '2026-10-05 10:00:00');
        $this->nota($venda, 300, '2026-10-05 11:00:00', ['chave_acesso' => str_repeat('1', 44)]);
        $outra = $this->pedido('mercado_livre', 200, '2026-10-06 10:00:00');
        $this->nota($outra, 200, '2026-10-06 11:00:00');
        $devolucao = $this->pedido(Order::ORIGIN_SALES_RETURN_INVOICE, 300, '2026-10-20 10:00:00', ['fiscal_operation_type' => 'sales_return', 'fiscal_referenced_nfe_key' => str_repeat('1', 44)]);
        $this->nota($devolucao, 300, '2026-10-20 10:05:00');

        $base = ContributionMargin::baseImposto(...$this->outubro());

        // 300 + 200 de venda − 300 devolvidos; o pedido devolvido não some da base, quem abate é a nota de devolução.
        $this->assertEqualsWithDelta(200.0, $base['mercado_livre'], 0.001);
        $this->assertArrayNotHasKey(Order::ORIGIN_SALES_RETURN_INVOICE, $base);
        $this->assertEqualsWithDelta(8.0, array_sum(ContributionMargin::imposto(...$this->outubro())), 0.001);
    }

    public function test_nota_cancelada_nao_conta_e_pendente_com_chave_e_autorizacao_conta(): void
    {
        $cancelada = $this->pedido('shopee', 80, '2026-10-10 10:00:00');
        $this->nota($cancelada, 80, '2026-10-10 11:00:00', ['cancelada_em' => '2026-10-11 09:00:00']);
        // Nota do TikTok emitida pelo Bling: ficou "pending" aqui, mas tem chave e data de autorização.
        $tiktok = $this->pedido('tiktok_shop', 79.90, '2026-10-05 10:00:00');
        $this->nota($tiktok, 79.90, '2026-10-05 19:43:48', ['status' => 'pending', 'chave_acesso' => str_repeat('2', 44)]);
        // Pendente de verdade (sem chave): não é nota ainda.
        $semChave = $this->pedido('tiktok_shop', 40, '2026-10-06 10:00:00');
        $this->nota($semChave, 40, '2026-10-06 11:00:00', ['status' => 'pending', 'autorizada_em' => null]);

        $base = ContributionMargin::baseImposto(...$this->outubro());

        $this->assertEqualsWithDelta(79.90, $base['tiktok_shop'], 0.001);
        $this->assertArrayNotHasKey('shopee', $base);
    }

    public function test_pedido_sem_nota_so_conta_pela_receita_enquanto_e_recente(): void
    {
        // Antigo sem nota: sem nota não há imposto.
        $this->pedido('shopee', 500, '2026-10-15 10:00:00');
        // Recente (ontem) sem nota: entra pela receita até a nota chegar.
        $this->pedido('shopee', 70, '2026-11-11 10:00:00');

        $this->assertSame([], ContributionMargin::baseImposto(...$this->outubro()));
        $this->assertEqualsWithDelta(70.0, ContributionMargin::baseImposto(Carbon::parse('2026-11-01'), Carbon::parse('2026-12-01'))['shopee'], 0.001);
    }

    public function test_soma_dos_canais_continua_igual_ao_periodo_quando_o_canal_so_tem_devolucao(): void
    {
        // Venda em setembro, devolução (nota) em outubro: outubro só tem o abate desse canal.
        $venda = $this->pedido('amazon', 100, '2026-09-10 10:00:00');
        $this->nota($venda, 100, '2026-09-10 11:00:00', ['chave_acesso' => str_repeat('3', 44)]);
        $devolucao = $this->pedido(Order::ORIGIN_SALES_RETURN_INVOICE, 100, '2026-10-03 10:00:00', ['fiscal_operation_type' => 'sales_return', 'fiscal_referenced_nfe_key' => str_repeat('3', 44)]);
        $this->nota($devolucao, 100, '2026-10-03 10:05:00');
        $shopee = $this->pedido('shopee', 200, '2026-10-04 10:00:00');
        $this->nota($shopee, 200, '2026-10-04 11:00:00');

        $margem = app(ContributionMargin::class);
        $periodo = $margem->periodo(...$this->outubro());
        $canais = $margem->porCanal(...$this->outubro());

        $this->assertEqualsWithDelta(4.0, $periodo['imposto'], 0.001); // 4% de (200 − 100)
        $this->assertEqualsWithDelta(-4.0, $canais['amazon']['imposto'], 0.001);
        $this->assertEqualsWithDelta($periodo['imposto'], array_sum(array_column($canais, 'imposto')), 0.001);
        $this->assertEqualsWithDelta($periodo['lucro'], array_sum(array_column($canais, 'lucro')), 0.011);
    }
}
