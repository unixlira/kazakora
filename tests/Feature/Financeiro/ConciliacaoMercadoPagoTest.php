<?php

namespace Tests\Feature\Financeiro;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Financeiro\Models\MercadoPagoPagamento;
use App\Modules\Marketplace\Models\OrderChannelFee;
use App\Modules\Marketplace\Support\ContributionMargin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Conciliação do Mercado Pago (2026-10-11): a conta recebe ML e site; o
 * site é reconhecido pelo número da transação guardado na loja.
 */
class ConciliacaoMercadoPagoTest extends TestCase
{
    use RefreshDatabase;

    private function pedido(array $dados = []): Order
    {
        $pedido = Order::create(array_merge([
            'status' => Order::STATUS_COMPLETED,
            'origin' => Order::ORIGIN_MERCADO_LIVRE,
            'shipping_name' => 'Cliente', 'shipping_phone' => '11999999999', 'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X', 'shipping_number' => '1', 'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 100, 'total' => 100,
        ], $dados));
        $pedido->forceFill(['created_at' => '2026-09-10 10:00:00'])->save();

        return $pedido;
    }

    private function pagamento(int $id, array $dados): array
    {
        return array_merge([
            'id' => $id, 'date_created' => '2026-09-10T10:00:00.000-04:00', 'date_approved' => '2026-09-10T10:00:00.000-04:00',
            'money_release_date' => '2026-09-20T10:00:00.000-04:00', 'status' => 'approved', 'status_detail' => 'accredited',
            'operation_type' => 'regular_payment', 'order' => [], 'external_reference' => null, 'description' => 'Produto',
            'payment_method_id' => 'pix', 'transaction_amount' => 100, 'transaction_amount_refunded' => 0,
            'transaction_details' => ['net_received_amount' => 70],
        ], $dados);
    }

    public function test_separa_site_ml_e_bonus_grava_taxa_real_e_estorno(): void
    {
        config(['services.mercadopago.access_token' => 'token-teste']);

        $venda = $this->pedido(['external_order_id' => '2000001']);
        OrderChannelFee::create(['order_id' => $venda->id, 'channel' => 'mercado_livre', 'gross_amount' => 100, 'fee_amount' => 15, 'source' => 'api', 'computed_at' => now()]);
        $devolvida = $this->pedido(['external_order_id' => '2000002']);
        $site = $this->pedido(['origin' => Order::ORIGIN_STORE, 'subtotal' => 80, 'total' => 95]);
        Payment::create(['order_id' => $site->id, 'provider' => 'mercadopago', 'mercadopago_payment_id' => '900', 'method_type' => 'pix', 'amount' => 95, 'status' => 'succeeded']);
        $manual = $this->pedido(['external_order_id' => '2000003']);
        OrderChannelFee::create(['order_id' => $manual->id, 'channel' => 'mercado_livre', 'gross_amount' => 100, 'fee_amount' => 12, 'source' => 'manual', 'computed_at' => now()]);

        Http::fake(['api.mercadopago.com/*' => Http::sequence()
            ->push(['paging' => ['total' => 6], 'results' => [
                $this->pagamento(1, ['order' => ['type' => 'mercadolibre', 'id' => '2000001'], 'transaction_details' => ['net_received_amount' => 71.2]]),
                $this->pagamento(2, ['order' => ['type' => 'mercadolibre', 'id' => '2000002'], 'status' => 'refunded', 'transaction_amount_refunded' => 100]),
                $this->pagamento(900, ['transaction_amount' => 95, 'transaction_details' => ['net_received_amount' => 90.25]]),
                $this->pagamento(3, ['order' => ['type' => 'mercadolibre', 'id' => '2000003']]),
                $this->pagamento(4, ['operation_type' => 'money_transfer', 'description' => 'bonificaciones_flex', 'transaction_amount' => 9.99, 'transaction_details' => ['net_received_amount' => 9.99]]),
                $this->pagamento(5, ['operation_type' => 'money_transfer', 'description' => 'Transferência', 'transaction_amount' => 50]),
            ]])
            ->whenEmpty(Http::response(['paging' => ['total' => 0], 'results' => []])),
        ]);

        $this->artisan('mercadopago:conciliar', ['--desde' => '2026-09-08', '--ate' => '2026-09-12'])->assertSuccessful();

        $this->assertSame(['bonificacao' => 1, 'loja' => 1, 'mercado_livre' => 3, 'outro' => 1],
            MercadoPagoPagamento::query()->selectRaw('canal, COUNT(*) n')->groupBy('canal')->orderBy('canal')->pluck('n', 'canal')->map(fn ($n) => (int) $n)->all());

        // ML: tudo que separa a receita do que entrou (comissão + frete do vendedor).
        $taxa = $venda->fresh()->channelFee;
        $this->assertSame('report', $taxa->source);
        $this->assertEquals(28.80, (float) $taxa->fee_amount);
        $this->assertEquals(71.20, (float) $taxa->payout_amount);
        // Site: só a taxa do MP.
        $this->assertEquals(4.75, (float) $site->fresh()->channelFee->fee_amount);
        // Taxa digitada à mão fica.
        $this->assertEquals(12.0, (float) $manual->fresh()->channelFee->fee_amount);
        // Estornado por inteiro sai da receita.
        $this->assertNotNull($devolvida->fresh()->estornado_em);

        $conta = app(ContributionMargin::class)->periodo(Carbon::parse('2026-09-01'), Carbon::parse('2026-10-01'));
        $this->assertEquals(280.0, $conta['receita']); // 100 + 80 + 100 (a devolvida não)
        $this->assertEquals(9.99, $conta['bonificacoes']);
        $this->assertEquals(round(280 - (28.80 + 4.75 + 12) + 9.99, 2), $conta['margem']);
    }

    public function test_nota_avulsa_nao_conta_como_venda(): void
    {
        $venda = $this->pedido(['external_order_id' => '2000009']);
        OrderChannelFee::create(['order_id' => $venda->id, 'channel' => 'mercado_livre', 'gross_amount' => 100, 'fee_amount' => 30, 'source' => 'report', 'computed_at' => now()]);

        $this->assertSame(1, Order::query()->vendaEfetiva()->count());
        $venda->forceFill(['origin' => Order::ORIGIN_MANUAL_INVOICE])->save();
        $this->assertSame(0, Order::query()->vendaEfetiva()->count(), 'nota avulsa é reemissão, não venda nova');
    }
}
