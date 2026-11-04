<?php

namespace Tests\Feature\Api;

use App\Modules\Marketplace\Support\ImportadorDemonstrativoTikTok;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Demonstrativo do TikTok (arquivo real de 01–11/10/2026) entra na auditoria. */
class ImportadorDemonstrativoTikTokTest extends TestCase
{
    use RefreshDatabase;

    private const ARQUIVO = __DIR__.'/../../fixtures/tiktok-demonstrativo-exemplo.xlsx';

    public function test_importa_o_arquivo_real_e_confere_os_demonstrativos(): void
    {
        $pedido = \App\Modules\Checkout\Models\Order::create([
            'status' => 'paid', 'origin' => 'tiktok_shop', 'external_order_id' => '586431223315924063',
            'shipping_name' => 'C', 'shipping_phone' => '1', 'shipping_zip' => '01001000', 'shipping_street' => 'R', 'shipping_number' => '1',
            'shipping_neighborhood' => 'B', 'shipping_city' => 'SP', 'shipping_state' => 'SP', 'subtotal' => 99.90, 'total' => 49.99,
        ]);

        $r = app(ImportadorDemonstrativoTikTok::class)->importar(self::ARQUIVO, 'demo.xlsx');

        // Taxa real = vendas líquidas 49,99 − liquidado 37,99 = 12,00.
        $this->assertDatabaseHas('order_channel_fees', ['order_id' => $pedido->id, 'source' => 'report', 'gross_amount' => 49.99, 'fee_amount' => 12.00, 'payout_amount' => 37.99]);
        // Receita na margem oficial: as vendas líquidas do extrato, não o subtotal cheio.
        $this->assertSame(49.99, (float) \Illuminate\Support\Facades\DB::table('orders')->where('id', $pedido->id)->value(\Illuminate\Support\Facades\DB::raw(\App\Modules\Marketplace\Support\ContributionMargin::receitaSql())));

        $this->assertSame(99, $r['linhas']);
        $this->assertSame([], $r['divergencias'], 'soma das linhas tem que bater com a aba Extratos');
        $this->assertDatabaseHas('marketplace_settlement_details', [
            'channel' => 'tiktok_shop', 'external_order_id' => '586431223315924063', 'statement_id' => '7694824645153113877',
            'payout_amount' => 37.99, 'product_net_sales' => 49.99, 'settlement_date' => '2026-10-11',
        ]);

        // Reimportar o mesmo período não duplica.
        app(ImportadorDemonstrativoTikTok::class)->importar(self::ARQUIVO, 'demo.xlsx');
        $this->assertSame(99, DB::table('marketplace_settlement_details')->count());
    }

    public function test_chega_pela_api_e_importa_sozinho(): void
    {
        Storage::fake('local');
        config(['services.tiktok_reports.token' => 't']);

        $this->withHeader('Authorization', 'Bearer t')
            ->post('/api/tiktok/reports/import', ['file' => new UploadedFile(self::ARQUIVO, 'tiktok_demonstrativo.xlsx', null, null, true)])
            ->assertOk()
            ->assertJsonPath('arquivos.0.importado.linhas', 99);

        $this->assertDatabaseHas('tiktok_report_uploads', ['status' => 'importado', 'tipo' => 'liquidacao']);
    }

    private function linha(array $dados): int
    {
        return DB::table('marketplace_settlement_details')->insertGetId([
            'channel' => 'tiktok_shop', 'transaction_type' => 'Pedido', 'external_order_id' => '585581559103849979',
            'external_sku_id' => '1736780989365585230', 'settlement_date' => '2026-08-27', 'status' => 'Pagamento pendente',
            'product_net_sales' => 37.99, 'payout_amount' => 31.71, 'source_file' => 'a.xlsx',
            'created_at' => '2026-08-31 11:46:30', 'updated_at' => now(), ...$dados,
        ]);
    }

    /**
     * Produção 2026-10-18: o TikTok troca o ID provisório do demonstrativo
     * quando paga, e o mesmo pedido entrava duas vezes (receita e taxa em
     * dobro em 116 pedidos). Fica uma linha por lançamento.
     */
    public function test_paga_repetida_no_mesmo_pagamento_sai_e_gmv_do_mesmo_arquivo_fica(): void
    {
        $velha = now()->subDays(4);
        $base = ['status' => 'Pagos', 'transaction_type' => 'Pedido', 'external_order_id' => 'P1', 'external_sku_id' => 'S', 'payment_id' => 'PG1',
            'settlement_date' => '2026-10-26', 'product_net_sales' => 49.99, 'payout_amount' => 9.79, 'source_file' => 'a.xlsx'];
        $provisoria = DB::table('marketplace_settlement_details')->insertGetId(['channel' => 'tiktok_shop', 'statement_id' => '374', 'created_at' => $velha, 'updated_at' => $velha] + $base);
        $definitiva = DB::table('marketplace_settlement_details')->insertGetId(['channel' => 'tiktok_shop', 'statement_id' => '770', 'created_at' => now(), 'updated_at' => now()] + $base);

        // Mesmo valor e dia em demonstrativos diferentes, ambos vistos no último
        // arquivo, um criado numa importação anterior: cobranças reais.
        $gmv = ['status' => 'Pagamento pendente', 'transaction_type' => 'Pagamento de GMV para anúncios do TikTok', 'external_order_id' => 'G', 'external_sku_id' => '',
            'settlement_date' => '2026-10-30', 'product_net_sales' => 0, 'payout_amount' => -27, 'source_file' => 'a.xlsx', 'updated_at' => now()];
        $g1 = DB::table('marketplace_settlement_details')->insertGetId(['channel' => 'tiktok_shop', 'statement_id' => 'g1', 'created_at' => $velha] + $gmv);
        $g2 = DB::table('marketplace_settlement_details')->insertGetId(['channel' => 'tiktok_shop', 'statement_id' => 'g2', 'created_at' => now()] + $gmv);

        $removidas = app(ImportadorDemonstrativoTikTok::class)->removerLinhasSuperadas();

        $this->assertSame([$provisoria], $removidas['ids']);
        $this->assertDatabaseHas('marketplace_settlement_details', ['id' => $definitiva]);
        $this->assertDatabaseHas('marketplace_settlement_details', ['id' => $g1]);
        $this->assertDatabaseHas('marketplace_settlement_details', ['id' => $g2]);
    }

    public function test_linha_pendente_repetida_em_outra_importacao_sai_e_a_taxa_e_refeita(): void
    {
        $pedido = \App\Modules\Checkout\Models\Order::create([
            'status' => 'paid', 'origin' => 'tiktok_shop', 'external_order_id' => '585581559103849979',
            'shipping_name' => 'C', 'shipping_phone' => '1', 'shipping_zip' => '01001000', 'shipping_street' => 'R', 'shipping_number' => '1',
            'shipping_neighborhood' => 'B', 'shipping_city' => 'SP', 'shipping_state' => 'SP', 'subtotal' => 37.99, 'total' => 32.99,
        ]);
        $pendente = $this->linha(['statement_id' => '3689299302187107662']);
        $pago = $this->linha(['statement_id' => '7678349834398566151', 'status' => 'Pagos', 'source_file' => 'b.xlsx']);
        // Pendente que só trocou de ID provisório no download seguinte (mesmo nome de arquivo, outro horário).
        $velha = $this->linha(['external_order_id' => '586202002709513953', 'statement_id' => '3725403326006854990', 'created_at' => '2026-10-12 00:53:57', 'updated_at' => '2026-10-12 00:53:57']);
        $nova = $this->linha(['external_order_id' => '586202002709513953', 'statement_id' => '7695197615289403142', 'created_at' => '2026-10-12 23:30:59', 'updated_at' => '2026-10-12 23:30:59']);
        // Duas cobranças de GMV iguais no MESMO arquivo são reais e ficam.
        $gmv = ['transaction_type' => 'Pagamento de GMV para anúncios do TikTok', 'external_order_id' => '7639742860367250197', 'external_sku_id' => '', 'settlement_date' => '2026-10-06', 'product_net_sales' => 0, 'payout_amount' => -27];
        $gmv1 = $this->linha([...$gmv, 'statement_id' => '3720583572082165070']);
        $gmv2 = $this->linha([...$gmv, 'statement_id' => '7693398376039941895']);
        // Mesmo demonstrativo com SKU nulo e "/" (formatos antigos): uma só.
        $skuNulo = $this->linha([...$gmv, 'statement_id' => '7673325528123737872', 'external_sku_id' => null, 'status' => 'Pagos', 'settlement_date' => '2026-08-13']);
        $skuBarra = $this->linha([...$gmv, 'statement_id' => '7673325528123737872', 'external_sku_id' => '/', 'status' => 'Pagos', 'settlement_date' => '2026-08-12', 'source_file' => 'c.xlsx']);
        // Dois lançamentos pagos do mesmo pedido em demonstrativos diferentes são reais.
        $pagoA = $this->linha(['external_order_id' => '585565655613277279', 'statement_id' => '7679247885233391361', 'status' => 'Pagos', 'product_net_sales' => 0, 'payout_amount' => -10.48]);
        $pagoB = $this->linha(['external_order_id' => '585565655613277279', 'statement_id' => '7680365486621787922', 'status' => 'Pagos', 'product_net_sales' => 0, 'payout_amount' => -10.86, 'source_file' => 'b.xlsx']);

        $this->artisan('tiktok:limpar-extrato --seco')->assertSuccessful();
        $this->assertSame(10, DB::table('marketplace_settlement_details')->count(), 'simulação não apaga nada');

        Storage::fake('local');
        $this->artisan('tiktok:limpar-extrato')->assertSuccessful();

        $ficaram = DB::table('marketplace_settlement_details')->pluck('id')->all();
        sort($ficaram);
        $esperado = [$pago, $nova, $gmv1, $gmv2, $skuBarra, $pagoA, $pagoB];
        sort($esperado);
        $this->assertSame($esperado, $ficaram);
        $this->assertNotContains($pendente, $ficaram);
        $this->assertNotContains($velha, $ficaram);
        $this->assertNotContains($skuNulo, $ficaram);

        // Taxa real refeita com a linha única: 37,99 − 31,71 = 6,28 (antes 12,56).
        $this->assertDatabaseHas('order_channel_fees', ['order_id' => $pedido->id, 'source' => 'report', 'gross_amount' => 37.99, 'fee_amount' => 6.28, 'payout_amount' => 31.71]);
        $this->assertSame(37.99, (float) DB::table('orders')->where('id', $pedido->id)->value(DB::raw(\App\Modules\Marketplace\Support\ContributionMargin::receitaSql())));
    }

    /**
     * Produção 04/11: o TikTok cobrou o frete do pedido (−21,90, pago em 10/10)
     * e devolveu em 03/11 (+21,90, pendente). A devolução não é cópia da cobrança.
     */
    public function test_devolucao_de_frete_pendente_nao_sai_como_copia_da_cobranca(): void
    {
        $frete = ['external_order_id' => '585843784962901509', 'product_net_sales' => 0];
        $cobranca = $this->linha([...$frete, 'statement_id' => '7694571046925879060', 'status' => 'Pagos', 'payment_id' => 'PG1', 'settlement_date' => '2026-10-10', 'payout_amount' => -21.90, 'source_file' => 'b.xlsx']);
        $devolucao = $this->linha([...$frete, 'statement_id' => '3752110925243516238', 'settlement_date' => '2026-11-03', 'payout_amount' => 21.90]);
        // Frete pendente que só trocou de ID ao ser pago continua saindo.
        $provisoria = $this->linha([...$frete, 'external_order_id' => '586025577681356517', 'statement_id' => '374', 'payout_amount' => -14.30]);
        $paga = $this->linha([...$frete, 'external_order_id' => '586025577681356517', 'statement_id' => '770', 'status' => 'Pagos', 'payout_amount' => -14.30, 'source_file' => 'b.xlsx']);

        app(ImportadorDemonstrativoTikTok::class)->removerLinhasSuperadas();

        $ficaram = DB::table('marketplace_settlement_details')->pluck('id')->sort()->values()->all();
        $this->assertSame([$cobranca, $devolucao, $paga], $ficaram);
        $this->assertNotContains($provisoria, $ficaram);
    }

    /** Produção 02/11: 38 linhas repetidas somavam acima do que o TikTok pagou. */
    public function test_extrato_acima_do_valor_pago_abre_alerta_e_some_quando_bate(): void
    {
        $repetida = $this->linha(['statement_id' => '374', 'status' => 'Pagos', 'payment_id' => 'PG1', 'payout_amount' => 100]);
        $this->linha(['statement_id' => '770', 'status' => 'Pagos', 'payment_id' => 'PG1', 'payout_amount' => 100]);
        $aba = [['ID do pagamento', 'Valor do pagamento'], ['PG1', '100.00']];
        $importador = app(ImportadorDemonstrativoTikTok::class);

        $excessos = $importador->conferirPagamentos($aba);

        $this->assertCount(1, $excessos);
        $this->assertStringContainsString('PG1', $excessos[0]);
        $this->assertDatabaseHas('admin_alertas', ['chave' => 'tiktok-extrato', 'resolvido_em' => null]);

        DB::table('marketplace_settlement_details')->where('id', $repetida)->delete();

        $this->assertSame([], $importador->conferirPagamentos($aba));
        $this->assertDatabaseMissing('admin_alertas', ['chave' => 'tiktok-extrato', 'resolvido_em' => null]);
    }

    public function test_pagamento_com_linhas_faltando_nao_e_duplicidade(): void
    {
        $this->linha(['statement_id' => '770', 'status' => 'Pagos', 'payment_id' => 'PG2', 'payout_amount' => 50]);

        $this->assertSame([], app(ImportadorDemonstrativoTikTok::class)->conferirPagamentos([['ID do pagamento', 'Valor do pagamento'], ['PG2', '100.00']]));
    }
}
