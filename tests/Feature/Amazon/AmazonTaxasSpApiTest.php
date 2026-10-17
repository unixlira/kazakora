<?php

namespace Tests\Feature\Amazon;

use App\Console\Commands\TaxasReaisAmazon;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\OrderChannelFee;
use App\Modules\Marketplace\Support\TaxasAmazonSpApi;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Taxa real da Amazon pela SP-API, Finances 2024-06-19 (2026-10-17), no lugar
 * da coleta pela tela da Seller Central. Formato das transações copiado de
 * uma resposta real de 16/10.
 */
class AmazonTaxasSpApiTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://sellingpartnerapi.test';

    protected function setUp(): void
    {
        parent::setUp();
        Sleep::fake();
        config([
            'services.amazon.sp_api_base_url' => self::BASE,
            'services.amazon.marketplace_id' => 'A2Q3Y263D00KWC',
            'app.timezone' => 'America/Sao_Paulo',
        ]);
        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_AMAZON, 'status' => MarketplaceAccount::STATUS_CONNECTED,
            'access_token' => 'lwa-token', 'refresh_token' => 'rt', 'token_expires_at' => now()->addMonth(),
        ]);
    }

    private function pedido(string $externo, int $diasAtras = 5, string $status = Order::STATUS_PAID): Order
    {
        return tap(Order::create([
            'status' => $status, 'origin' => Order::ORIGIN_AMAZON, 'external_order_id' => $externo,
            'shipping_name' => 'C', 'shipping_phone' => '1', 'shipping_zip' => '01001000', 'shipping_street' => 'R', 'shipping_number' => '1',
            'shipping_neighborhood' => 'B', 'shipping_city' => 'SP', 'shipping_state' => 'SP', 'subtotal' => 55.90, 'shipping_cost' => 18.02, 'total' => 73.92,
        ]), fn (Order $o) => $o->forceFill(['created_at' => now()->subDays($diasAtras)])->save());
    }

    private function valor(float $v): array
    {
        return ['currencyAmount' => $v, 'currencyCode' => 'BRL'];
    }

    /**
     * Transação como a Finances API devolve.
     *
     * @param  array<string, string>  $extras  identificadores além do pedido
     */
    private function transacao(string $tipo, string $status, string $pedido, string $id, float $produto, float $frete, float $comissao, float $envioHb, array $extras = [], string $data = '2026-10-15T13:40:55Z'): array
    {
        $sinal = $tipo === 'Refund' ? -1 : 1;
        $taxas = -($comissao + $envioHb) * $sinal;
        $total = round(($produto + $frete) * $sinal + $taxas, 2);
        $ids = ['ORDER_ID' => $pedido, 'SHIPMENT_ID' => '2652'.substr(md5($pedido), 0, 6), 'SETTLEMENT_ID' => '27499138751'] + $extras;

        return [
            'transactionType' => $tipo,
            'transactionId' => $id,
            'transactionStatus' => $status,
            'relatedIdentifiers' => array_map(fn ($n, $v) => ['relatedIdentifierName' => $n, 'relatedIdentifierValue' => $v], array_keys($ids), $ids),
            'totalAmount' => $this->valor($total),
            'description' => $tipo === 'Refund' ? 'Refund' : 'Order Payment',
            'postedDate' => $data,
            'items' => [[
                'description' => 'Carregador portátil Mini Power Bank 10000mAh',
                'totalAmount' => $this->valor($total),
                'breakdowns' => [
                    ['breakdownType' => 'ProductCharges', 'breakdownAmount' => $this->valor($produto * $sinal), 'breakdowns' => []],
                    ['breakdownType' => 'Shipping', 'breakdownAmount' => $this->valor($frete * $sinal), 'breakdowns' => []],
                    ['breakdownType' => 'AmazonFees', 'breakdownAmount' => $this->valor($taxas), 'breakdowns' => [
                        ['breakdownType' => 'Commission', 'breakdownAmount' => $this->valor(-$comissao * $sinal), 'breakdowns' => []],
                        ['breakdownType' => 'ShippingHB', 'breakdownAmount' => $this->valor(-$envioHb * $sinal), 'breakdowns' => []],
                    ]],
                ],
                'contexts' => [['contextType' => 'ProductContext', 'sku' => 'CAR-ROSA', 'quantityShipped' => 1, 'fulfillmentNetwork' => 'MFN']],
            ]],
        ];
    }

    /**
     * Uma execução por lista de páginas (a fila de respostas é uma só).
     *
     * @param  list<list<array>>  ...$execucoes
     */
    private function fakeApi(array ...$execucoes): void
    {
        $respostas = [];
        foreach ($execucoes as $paginas) {
            foreach ($paginas as $i => $transacoes) {
                $respostas[] = Http::response(['payload' => array_filter(['transactions' => $transacoes, 'nextToken' => $i < count($paginas) - 1 ? 'pagina-'.($i + 1) : null])]);
            }
        }
        Http::fake([self::BASE.'/finances/*' => Http::sequence($respostas)]);
    }

    public function test_grava_taxa_real_sem_duplicar_diferido_e_liberado_e_e_idempotente(): void
    {
        $a = $this->pedido('701-0000000-0000001');
        $b = $this->pedido('702-0000000-0000002');
        $this->fakeApi([
            [
                // Mesmo envio duas vezes: diferido e depois liberado (aponta para o diferido).
                $this->transacao('Shipment', 'DEFERRED_RELEASED', '701-0000000-0000001', 'tx-dif', 55.90, 18.02, 8.39, 2.70, ['RELEASE_TRANSACTION_ID' => 'tx-lib'], '2026-10-10T18:03:25Z'),
                $this->transacao('Shipment', 'RELEASED', '701-0000000-0000001', 'tx-lib', 55.90, 18.02, 8.39, 2.70, ['DEFERRED_TRANSACTION_ID' => 'tx-dif']),
            ],
            [
                // Ainda retido (entrega não confirmada): a taxa já é a definitiva.
                $this->transacao('Shipment', 'DEFERRED', '702-0000000-0000002', 'tx-b', 59.90, 22.35, 10.21, 2.70),
                ['transactionType' => 'Transfer', 'transactionId' => 'tx-t', 'transactionStatus' => 'RELEASED', 'relatedIdentifiers' => [], 'totalAmount' => $this->valor(185.85), 'items' => []],
            ],
        ], [[
            $this->transacao('Shipment', 'RELEASED', '701-0000000-0000001', 'tx-lib', 55.90, 18.02, 8.39, 2.70, ['DEFERRED_TRANSACTION_ID' => 'tx-dif']),
            $this->transacao('Shipment', 'DEFERRED', '702-0000000-0000002', 'tx-b', 59.90, 22.35, 10.21, 2.70),
        ]]);

        $this->artisan('amazon:taxas-reais', ['--dias' => 30])->assertSuccessful();

        // Duas páginas, com o filtro de data e o marketplace.
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), self::BASE.'/finances/2024-06-19/transactions')
            && $r['marketplaceId'] === 'A2Q3Y263D00KWC' && isset($r['postedAfter']) && $r->hasHeader('x-amz-access-token', 'lwa-token'));
        Http::assertSent(fn (Request $r) => ($r['nextToken'] ?? null) === 'pagina-1');

        // 55,90 + 18,02 = 73,92 de venda; taxa 11,09 (comissão 8,39 + envio 2,70), uma vez só.
        $this->assertDatabaseHas('order_channel_fees', [
            'order_id' => $a->id, 'channel' => 'amazon', 'source' => 'report',
            'gross_amount' => 73.92, 'fee_amount' => 11.09, 'payout_amount' => 62.83, 'commission_fee' => 8.39, 'shipping_fee' => 2.70,
        ]);
        $this->assertDatabaseHas('order_channel_fees', ['order_id' => $b->id, 'fee_amount' => 12.91, 'source' => 'report']);
        $this->assertDatabaseHas('marketplace_settlement_details', [
            'channel' => 'amazon', 'statement_id' => '', 'external_sku_id' => '', 'external_order_id' => '701-0000000-0000001',
            'transaction_type' => 'Order', 'status' => 'RELEASED', 'quantity' => 1, 'source_file' => TaxasAmazonSpApi::ORIGEM, 'settlement_date' => '2026-10-15',
        ]);
        $this->assertSame(2, DB::table('marketplace_settlement_details')->count());
        $this->assertTrue(Cache::get(TaxasReaisAmazon::CACHE_ULTIMA)['ok']);

        // Rodar de novo não muda nada.
        $calculadaEm = OrderChannelFee::where('order_id', $a->id)->value('computed_at');
        $this->travelTo(now()->addHour());
        $this->artisan('amazon:taxas-reais')->expectsOutputToContain('0 nova(s), 0 alterada(s), 2 igual(is)')->assertSuccessful();
        $this->assertSame(2, DB::table('marketplace_settlement_details')->count());
        $this->assertSame(2, OrderChannelFee::count());
        $this->assertEquals($calculadaEm, OrderChannelFee::where('order_id', $a->id)->value('computed_at'));
    }

    public function test_substitui_a_linha_da_cora_box_sem_somar_em_dobro_e_nao_mexe_na_taxa_manual(): void
    {
        $cora = $this->pedido('701-0000000-0000001');
        $manual = $this->pedido('702-0000000-0000002');
        // Linha gravada pela coleta antiga (tela da Seller Central), mesma chave.
        DB::table('marketplace_settlement_details')->insert([
            'channel' => 'amazon', 'statement_id' => '', 'external_order_id' => '701-0000000-0000001', 'external_sku_id' => '', 'transaction_type' => 'Order',
            'payout_amount' => 65.34, 'product_net_sales' => 55.90, 'customer_shipping_fee' => 22.35, 'platform_commission_fee' => -12.91,
            'source_file' => 'amazon_transacoes_30d_2026-10-17.csv', 'created_at' => now(), 'updated_at' => now(),
        ]);
        OrderChannelFee::create(['order_id' => $cora->id, 'channel' => 'amazon', 'gross_amount' => 78.25, 'fee_amount' => 12.91, 'payout_amount' => 65.34, 'source' => 'report', 'computed_at' => now()]);
        OrderChannelFee::create(['order_id' => $manual->id, 'channel' => 'amazon', 'gross_amount' => 73.92, 'fee_amount' => 9.99, 'source' => 'manual', 'computed_at' => now()]);

        $this->fakeApi([[
            $this->transacao('Shipment', 'RELEASED', '701-0000000-0000001', 'tx-a', 55.90, 22.35, 10.21, 2.70),
            $this->transacao('Shipment', 'RELEASED', '702-0000000-0000002', 'tx-b', 55.90, 18.02, 8.39, 2.70),
        ]]);

        $this->artisan('amazon:taxas-reais')->expectsOutputToContain('0 nova(s), 0 alterada(s), 1 igual(is), 1 manual(is) mantida(s)')->assertSuccessful();

        $this->assertSame(1, DB::table('marketplace_settlement_details')->where('external_order_id', '701-0000000-0000001')->count());
        $this->assertDatabaseHas('marketplace_settlement_details', ['external_order_id' => '701-0000000-0000001', 'source_file' => TaxasAmazonSpApi::ORIGEM, 'platform_commission_fee' => -10.21, 'shipping_cost' => -2.70, 'payout_amount' => 65.34]);
        // Mesma taxa da coleta antiga: não é regravada.
        $this->assertDatabaseHas('order_channel_fees', ['order_id' => $cora->id, 'fee_amount' => 12.91, 'payout_amount' => 65.34, 'source' => 'report']);
        $this->assertDatabaseHas('order_channel_fees', ['order_id' => $manual->id, 'gross_amount' => 73.92, 'fee_amount' => 9.99, 'source' => 'manual', 'computed_at' => now()]);
    }

    public function test_seco_nao_grava_nada_e_mostra_a_conferencia(): void
    {
        $pedido = $this->pedido('701-0000000-0000001');
        OrderChannelFee::create(['order_id' => $pedido->id, 'channel' => 'amazon', 'gross_amount' => 73.92, 'fee_amount' => 12.00, 'payout_amount' => 61.92, 'source' => 'report', 'computed_at' => now()]);
        $this->fakeApi([[$this->transacao('Shipment', 'RELEASED', '701-0000000-0000001', 'tx-a', 55.90, 18.02, 8.39, 2.70)]]);

        $this->artisan('amazon:taxas-reais', ['--seco' => true])
            ->expectsOutputToContain('[SIMULAÇÃO, nada gravado]')
            ->expectsOutputToContain('0 nova(s), 1 alterada(s), 0 igual(is)')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('marketplace_settlement_details')->count());
        $this->assertDatabaseHas('order_channel_fees', ['order_id' => $pedido->id, 'fee_amount' => 12.00]);
        $this->assertNull(Cache::get(TaxasReaisAmazon::CACHE_ULTIMA));
    }

    public function test_reembolso_fica_registrado_nao_muda_a_taxa_e_e_apontado_se_ainda_conta_como_venda(): void
    {
        $pedido = $this->pedido('701-0000000-0000001');
        $this->fakeApi([[
            $this->transacao('Shipment', 'RELEASED', '701-0000000-0000001', 'tx-a', 55.90, 18.29, 9.54, 2.70),
            $this->transacao('Refund', 'DEFERRED_RELEASED', '701-0000000-0000001', 'tx-r1', 55.90, 18.29, 7.09, 2.70, ['REFUND_ID' => 'r', 'RELEASE_TRANSACTION_ID' => 'tx-r2']),
            $this->transacao('Refund', 'RELEASED', '701-0000000-0000001', 'tx-r2', 55.90, 18.29, 7.09, 2.70, ['REFUND_ID' => 'r', 'DEFERRED_TRANSACTION_ID' => 'tx-r1']),
        ]]);

        $this->artisan('amazon:taxas-reais')
            ->expectsOutputToContain('Reembolsados na Amazon que ainda contam como venda')
            ->assertSuccessful();

        $this->assertDatabaseHas('order_channel_fees', ['order_id' => $pedido->id, 'fee_amount' => 12.24]);
        $this->assertDatabaseHas('marketplace_settlement_details', [
            'external_order_id' => '701-0000000-0000001', 'transaction_type' => 'Refund', 'product_refunds' => -55.90, 'payout_amount' => -64.40,
        ]);
        $this->assertSame(1, DB::table('marketplace_settlement_details')->where('transaction_type', 'Refund')->count());
    }

    public function test_pedido_fora_da_janela_ou_com_liquidacao_oficial_nao_e_tocado(): void
    {
        $antigo = $this->pedido('701-0000000-0000001', 40);
        $liquidado = $this->pedido('702-0000000-0000002');
        DB::table('marketplace_settlement_details')->insert([
            'channel' => 'amazon', 'statement_id' => '123456', 'external_order_id' => '702-0000000-0000002', 'external_sku_id' => 'CAR-ROSA', 'transaction_type' => 'Order',
            'payout_amount' => 60.00, 'product_net_sales' => 55.90, 'customer_shipping_fee' => 18.02, 'source_file' => 'flat.txt', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->fakeApi([[
            $this->transacao('Shipment', 'RELEASED', '701-0000000-0000001', 'tx-a', 55.90, 18.02, 8.39, 2.70),
            $this->transacao('Shipment', 'RELEASED', '702-0000000-0000002', 'tx-b', 55.90, 18.02, 8.39, 2.70),
            $this->transacao('Shipment', 'RELEASED', '701-9999999-9999999', 'tx-c', 55.90, 18.02, 8.39, 2.70),
        ]]);

        $this->artisan('amazon:taxas-reais', ['--dias' => 30])
            ->expectsOutputToContain('Na API sem pedido no sistema: 1 (701-9999999-9999999)')
            ->assertSuccessful();

        $this->assertDatabaseMissing('order_channel_fees', ['order_id' => $antigo->id]);
        $this->assertDatabaseMissing('order_channel_fees', ['order_id' => $liquidado->id]);
        $this->assertSame(1, DB::table('marketplace_settlement_details')->count());
    }

    public function test_limite_de_chamadas_tenta_de_novo_e_erro_fica_registrado_para_a_vigia(): void
    {
        $pedido = $this->pedido('701-0000000-0000001');
        Http::fake([self::BASE.'/finances/*' => Http::sequence([
            Http::response(['errors' => [['code' => 'QuotaExceeded', 'message' => 'You exceeded your quota']]], 429),
            Http::response(['payload' => ['transactions' => [$this->transacao('Shipment', 'RELEASED', '701-0000000-0000001', 'tx-a', 55.90, 18.02, 8.39, 2.70)]]]),
            Http::response(['errors' => [['code' => 'Unauthorized', 'message' => 'Access to requested resource is denied.']]], 403),
        ])]);

        $this->artisan('amazon:taxas-reais')->assertSuccessful();
        $this->assertDatabaseHas('order_channel_fees', ['order_id' => $pedido->id, 'fee_amount' => 11.09]);

        $this->artisan('amazon:taxas-reais')->assertFailed();
        $ultima = Cache::get(TaxasReaisAmazon::CACHE_ULTIMA);
        $this->assertFalse($ultima['ok']);
        $this->assertSame('Access to requested resource is denied.', $ultima['erro']);
        $this->assertNotNull($ultima['ultimo_ok']);
    }

    public function test_sem_conta_conectada_nao_chama_a_api(): void
    {
        MarketplaceAccount::query()->update(['status' => MarketplaceAccount::STATUS_DISCONNECTED]);
        Http::fake();

        $this->artisan('amazon:taxas-reais')->expectsOutputToContain('não conectada')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_agendado_de_hora_em_hora_antes_do_resultado_das_23h50(): void
    {
        $evento = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'amazon:taxas-reais'));

        $this->assertNotNull($evento);
        $this->assertSame('35 * * * *', $evento->expression);
    }
}
