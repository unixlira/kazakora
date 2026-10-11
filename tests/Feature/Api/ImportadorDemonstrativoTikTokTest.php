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
}
