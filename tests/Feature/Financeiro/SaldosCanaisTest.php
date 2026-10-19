<?php

namespace Tests\Feature\Financeiro;

use App\Models\User;
use App\Modules\Financeiro\Models\SaldoCanal;
use App\Modules\Marketplace\Models\ChannelWalletBalance;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\TaxasAmazonSpApi;
use App\Services\Amazon\AmazonClient;
use App\Services\Shopee\ShopeeWalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Aba "Saldos nos canais" do Fluxo de Caixa (2026-10-19): a coleta de
 * hora em hora grava uma foto por canal com saldo, a receber e repasses
 * com data; erro num canal não derruba os outros e vira foto "erro"; a
 * tela soma por empresa e marca fonte com erro ou com mais de 2 h.
 */
class SaldosCanaisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-19 10:30:00');
        config(['services.mercadopago.access_token' => 'token-teste']);

        foreach ([MarketplaceAccount::CHANNEL_SHOPEE, MarketplaceAccount::CHANNEL_MERCADO_LIVRE, MarketplaceAccount::CHANNEL_AMAZON, MarketplaceAccount::CHANNEL_BLING] as $canal) {
            MarketplaceAccount::create(['channel' => $canal, 'status' => MarketplaceAccount::STATUS_CONNECTED, 'seller_id' => '1']);
        }

        ChannelWalletBalance::create(['channel' => 'mercado_livre', 'balance' => 840.62, 'balance_as_of' => now()]);
    }

    private function fakeShopee(bool $falhar = false): void
    {
        $shopee = Mockery::mock(ShopeeWalletService::class);
        if ($falhar) {
            $shopee->shouldReceive('currentBalance')->andThrow(new RuntimeException('Erro na API https://x/api?access_token=SEGREDO123&sign=abc'));
        } else {
            $shopee->shouldReceive('currentBalance')->andReturn(3201.94);
            $shopee->shouldReceive('incomeOverview')->andReturn(['pendente' => 150.0, 'liberado' => 35149.48]);
            $shopee->shouldReceive('pendingIncome')->andReturn([
                ['order_sn' => 'A', 'estimated_escrow_amount' => 100.0, 'estimated_payout_time' => Carbon::parse('2026-10-19 20:00')->timestamp, 'status' => 'Pagamento iniciado'],
                ['order_sn' => 'B', 'estimated_escrow_amount' => 50.0, 'estimated_payout_time' => 0, 'status' => 'Aguardando o pedido ser concluído'],
                ['order_sn' => 'B', 'estimated_escrow_amount' => 50.0, 'estimated_payout_time' => 0, 'status' => 'Aguardando o pedido ser concluído'],
            ]);
        }
        $this->app->instance(ShopeeWalletService::class, $shopee);
    }

    private function fakeAmazon(): void
    {
        $client = Mockery::mock(AmazonClient::class);
        $client->shouldReceive('get')->with('/finances/v0/financialEventGroups', Mockery::any())->andReturn(['payload' => ['FinancialEventGroupList' => [
            ['ProcessingStatus' => 'Open', 'OriginalTotal' => ['CurrencyAmount' => 20.0]],
            ['ProcessingStatus' => 'Pending', 'OriginalTotal' => ['CurrencyAmount' => 673.03]],
            ['ProcessingStatus' => 'Closed', 'FundTransferStatus' => 'Succeeded', 'OriginalTotal' => ['CurrencyAmount' => 185.85]],
        ]]]);
        $this->app->instance(AmazonClient::class, $client);

        $transacoes = Mockery::mock(TaxasAmazonSpApi::class);
        $transacoes->shouldReceive('buscar')->andReturn([
            // Retida, ainda não liberada: entra com a data de liberação.
            ['transactionId' => 'T1', 'transactionStatus' => 'DEFERRED', 'totalAmount' => ['currencyAmount' => 61.72],
                'contexts' => [['contextType' => 'DeferredContext', 'maturityDate' => '2026-11-07T02:59:59Z']]],
            // Retida e já liberada (o DEFERRED_RELEASED aponta para ela): não entra.
            ['transactionId' => 'T2', 'transactionStatus' => 'DEFERRED', 'totalAmount' => ['currencyAmount' => 99.0],
                'contexts' => [['contextType' => 'DeferredContext', 'maturityDate' => '2026-10-10T02:59:59Z']]],
            ['transactionId' => 'T3', 'transactionStatus' => 'DEFERRED_RELEASED', 'totalAmount' => ['currencyAmount' => 99.0],
                'relatedIdentifiers' => [['relatedIdentifierName' => 'DEFERRED_TRANSACTION_ID', 'relatedIdentifierValue' => 'T2']]],
        ]);
        $this->app->instance(TaxasAmazonSpApi::class, $transacoes);
    }

    private function fakeMercadoPago(): void
    {
        Http::fake(['api.mercadopago.com/v1/payments/search*' => function ($request) {
            // Só a primeira janela traz pagamentos; as outras vêm vazias.
            if (! str_contains(urldecode($request->url()), 'begin_date=2026-07-21')) {
                return Http::response(['results' => [], 'paging' => ['total' => 0]]);
            }

            return Http::response(['results' => [
                ['id' => 1, 'status' => 'approved', 'money_release_status' => 'pending', 'money_release_date' => '2026-10-23T10:00:00.000-04:00',
                    'order' => ['type' => 'mercadolibre', 'id' => '200'], 'transaction_details' => ['net_received_amount' => 80.0], 'transaction_amount_refunded' => 0],
                ['id' => 2, 'status' => 'approved', 'money_release_status' => 'pending', 'money_release_date' => '2026-10-10T10:00:00.000-04:00',
                    'order' => ['type' => 'mercadolibre', 'id' => '201'], 'transaction_details' => ['net_received_amount' => 20.0], 'transaction_amount_refunded' => 0],
                ['id' => 3, 'status' => 'approved', 'money_release_status' => 'released', 'money_release_date' => '2026-10-01T10:00:00.000-04:00',
                    'order' => ['type' => 'mercadolibre', 'id' => '202'], 'transaction_details' => ['net_received_amount' => 500.0], 'transaction_amount_refunded' => 0],
            ], 'paging' => ['total' => 3]]);
        }]);
    }

    public function test_coleta_grava_saldo_a_receber_e_repasses_de_cada_canal(): void
    {
        $this->fakeShopee();
        $this->fakeAmazon();
        $this->fakeMercadoPago();

        $this->artisan('financeiro:coletar-saldos')->assertSuccessful();

        $shopee = SaldoCanal::query()->where('canal', 'shopee')->sole();
        $this->assertSame('ok', $shopee->status);
        $this->assertEquals(3201.94, (float) $shopee->disponivel);
        $this->assertEquals(150.0, (float) $shopee->a_receber);
        $this->assertEquals([
            ['data' => '2026-10-19', 'valor' => 100.0, 'rotulo' => 'Pagamento iniciado'],
            ['data' => null, 'valor' => 50.0, 'rotulo' => 'Aguardando o pedido ser concluído (a Shopee não dá data)'],
        ], $shopee->repasses);

        $ml = SaldoCanal::query()->where('canal', 'mercado_livre')->sole();
        $this->assertEquals(840.62, (float) $ml->disponivel);
        $this->assertEquals(100.0, (float) $ml->a_receber);
        $this->assertSame('2026-10-23', $ml->repasses[0]['data']);
        $this->assertNull($ml->repasses[1]['data']);
        $this->assertEquals(20.0, $ml->repasses[1]['valor']);
        $this->assertEquals(100.0, $ml->detalhe['a_receber_ml']);

        $amazon = SaldoCanal::query()->where('canal', 'amazon')->sole();
        $this->assertEquals(693.03, (float) $amazon->disponivel);
        $this->assertEquals(61.72, (float) $amazon->a_receber);
        $this->assertSame('2026-11-06', collect($amazon->repasses)->whereNotNull('data')->first()['data']);

        $tiktok = SaldoCanal::query()->where('canal', 'tiktok_shop')->sole();
        $this->assertSame('parcial', $tiktok->status);
        $this->assertNull($tiktok->disponivel);
        $this->assertTrue($tiktok->detalhe['estimado']);

        // FerraKora e Beauty Kora não têm conta em marketplace: nenhuma foto.
        $this->assertSame(0, SaldoCanal::query()->where('empresa', '<>', 'kazakora')->count());
    }

    public function test_erro_num_canal_nao_para_os_outros_e_nao_mostra_segredo(): void
    {
        $this->fakeShopee(falhar: true);
        $this->fakeAmazon();
        $this->fakeMercadoPago();

        $this->artisan('financeiro:coletar-saldos')->assertSuccessful();

        $shopee = SaldoCanal::query()->where('canal', 'shopee')->sole();
        $this->assertSame('erro', $shopee->status);
        $this->assertStringNotContainsString('SEGREDO123', $shopee->erro);
        $this->assertSame(4, SaldoCanal::query()->count());
    }

    public function test_seco_nao_grava(): void
    {
        $this->fakeShopee();
        $this->fakeAmazon();
        $this->fakeMercadoPago();

        $this->artisan('financeiro:coletar-saldos', ['--seco' => true, '--canal' => ['shopee']])->assertSuccessful();

        $this->assertSame(0, SaldoCanal::query()->count());
    }

    public function test_aba_soma_por_empresa_e_marca_fonte_com_erro_ou_atrasada(): void
    {
        $foto = fn (string $canal, string $quando, string $status, ?float $disp, ?float $receber, ?string $erro = null) => SaldoCanal::create([
            'empresa' => 'kazakora', 'canal' => $canal, 'coletado_em' => $quando, 'status' => $status,
            'disponivel' => $disp, 'a_receber' => $receber, 'repasses' => [], 'detalhe' => [], 'erro' => $erro,
        ]);

        $foto('shopee', '2026-10-19 09:10:00', 'ok', 100, 50);
        $foto('shopee', '2026-10-19 10:10:00', 'ok', 120, 40);
        // Mercado Livre: a última tentativa falhou; vale a foto boa anterior, marcada.
        $foto('mercado_livre', '2026-10-19 09:10:00', 'ok', 800, 200);
        $foto('mercado_livre', '2026-10-19 10:10:00', 'erro', null, null, 'HTTP 500');
        // Amazon: última foto há 5 h.
        $foto('amazon', '2026-10-19 05:10:00', 'ok', 10, 5);

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)->get('/admin/fluxo-de-caixa?aba=saldos')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filtro.aba', 'saldos')
                ->has('saldos.empresas', 3)
                ->where('saldos.empresas.0.empresa', 'kazakora')
                ->where('saldos.empresas.0.disponivel', 930)
                ->where('saldos.empresas.0.a_receber', 245)
                ->where('saldos.empresas.0.cards.0.canal', 'shopee')
                ->where('saldos.empresas.0.cards.0.disponivel', 120)
                ->where('saldos.empresas.0.cards.0.desatualizado', false)
                ->has('saldos.empresas.0.cards.0.serie', 2)
                ->where('saldos.empresas.0.cards.1.erro', 'HTTP 500')
                ->where('saldos.empresas.0.cards.1.disponivel', 800)
                ->where('saldos.empresas.0.cards.3.canal', 'amazon')
                ->where('saldos.empresas.0.cards.3.desatualizado', true)
                ->where('saldos.empresas.1.tem_integracao', false)
                ->where('saldos.empresas.2.tem_integracao', false)
                ->where('saldos.geral.disponivel', 930)
                ->where('saldos.geral.a_receber', 245)
                ->missing('resumo'));
    }
}
