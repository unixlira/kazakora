<?php

namespace Tests\Unit\MercadoPago;

use App\Services\MercadoPago\MercadoPagoWalletService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pedido explícito 2026-08-09/10: saldo disponível pra saque via relatório
 * assíncrono do Mercado Pago. CSV de teste abaixo é uma amostra REAL
 * baixada ao vivo da conta de produção (KORAMIX) — não inventada, só com o
 * SOURCE_ID/valores mantidos como vieram pra travar o parsing certo.
 */
class MercadoPagoWalletServiceTest extends TestCase
{
    private const REAL_SAMPLE_CSV = <<<'CSV'
        DATE;SOURCE_ID;DESCRIPTION;NET_CREDIT_AMOUNT;NET_DEBIT_AMOUNT;GROSS_AMOUNT;MP_FEE_AMOUNT;TAXES_AMOUNT;PAYMENT_METHOD;TRANSACTION_APPROVAL_DATE;BUSINESS_UNIT;SUB_UNIT;BALANCE_AMOUNT;PAYMENT_METHOD_TYPE;PURCHASE_ID
        2026-08-02T00:00:00.000-03:00;;;351.98;0.00;351.98;0.00;0.00;;;;;351.98;;
        2026-08-03T13:49:24.000-03:00;167811120839;payment;106.05;0.00;182.40;-19.53;0.00;master;2026-07-13T20:09:20.000-03:00;Mercado Libre; ;458.03;credit_card;
        2026-08-08T00:12:00.000-03:00;172677044586;payment;0.00;24.24;-24.24;0.00;0.00;available_money;2026-08-08T00:12:00.000-03:00;Mercado Libre; ;500.49;account_money;
        ;;;500.49;0.00;597.13;-37.54;0.00;;;;;0.00;;
        CSV;

    public function test_download_balance_returns_the_balance_of_the_last_row_that_has_a_date(): void
    {
        Http::fake([
            '*/v1/account/release_report/*' => Http::response(self::REAL_SAMPLE_CSV),
        ]);

        config(['services.mercadopago.access_token' => 'fake-token']);

        $balance = app(MercadoPagoWalletService::class)->downloadBalance('reserve-release-test.csv');

        // 500.49 é a última linha COM data — a última linha do arquivo
        // (500.49 de novo, mas em BALANCE_AMOUNT=0.00) é o rodapé de
        // totais, sem data, e precisa ser ignorada.
        $this->assertSame(500.49, $balance);
    }

    public function test_download_balance_returns_null_when_the_report_has_no_dated_rows(): void
    {
        Http::fake([
            '*/v1/account/release_report/*' => Http::response("DATE;BALANCE_AMOUNT\n;0.00"),
        ]);

        config(['services.mercadopago.access_token' => 'fake-token']);

        $balance = app(MercadoPagoWalletService::class)->downloadBalance('empty.csv');

        $this->assertNull($balance);
    }

    public function test_latest_ready_report_picks_the_most_recent_enabled_one(): void
    {
        Http::fake([
            '*/v1/account/release_report/list' => Http::response([
                ['file_name' => 'older.csv', 'date_created' => '2026-08-08T10:00:00.000-03:00', 'status' => 'enabled'],
                ['file_name' => 'pending.csv', 'date_created' => '2026-08-10T09:00:00.000-03:00', 'status' => 'pending'],
                ['file_name' => 'newest.csv', 'date_created' => '2026-08-10T08:00:00.000-03:00', 'status' => 'enabled'],
            ]),
        ]);

        config(['services.mercadopago.access_token' => 'fake-token']);

        $report = app(MercadoPagoWalletService::class)->latestReadyReport();

        $this->assertSame('newest.csv', $report['file_name']);
    }

    public function test_latest_ready_report_returns_null_when_nothing_is_enabled_yet(): void
    {
        Http::fake(['*/v1/account/release_report/list' => Http::response([
            ['file_name' => 'pending.csv', 'date_created' => now()->toIso8601String(), 'status' => 'pending'],
        ])]);

        config(['services.mercadopago.access_token' => 'fake-token']);

        $this->assertNull(app(MercadoPagoWalletService::class)->latestReadyReport());
    }

    public function test_request_report_sends_dates_without_milliseconds(): void
    {
        // Achado real 2026-08-09: com milissegundos (.000Z) a API sempre
        // recusava com "Must specify begin_date parameter" mesmo o campo
        // estando presente — sem eles funciona (202 confirmado ao vivo).
        Http::fake(['*/v1/account/release_report' => Http::response([], 202)]);
        config(['services.mercadopago.access_token' => 'fake-token']);

        app(MercadoPagoWalletService::class)->requestReport(
            Carbon::parse('2026-08-01 00:00:00', 'UTC'),
            Carbon::parse('2026-08-08 00:00:00', 'UTC'),
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['begin_date'] === '2026-08-01T00:00:00Z'
                && $body['end_date'] === '2026-08-08T00:00:00Z';
        });
    }
}
