<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Support\ImportadorRelatorioAmazon;
use App\Notifications\AmazonRelatorioNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Relatórios de pagamento da Amazon: POST /api/amazon/reports/import e importação (2026-10-12). */
class AmazonReportsImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['services.amazon_reports.token' => 'segredo-de-teste']);
    }

    private function pedido(string $id): Order
    {
        return Order::create([
            'status' => 'paid', 'origin' => 'amazon', 'external_order_id' => $id,
            'shipping_name' => 'C', 'shipping_phone' => '1', 'shipping_zip' => '01001000', 'shipping_street' => 'R', 'shipping_number' => '1',
            'shipping_neighborhood' => 'B', 'shipping_city' => 'SP', 'shipping_state' => 'SP', 'subtotal' => 55.90, 'shipping_cost' => 18.02, 'total' => 73.92,
        ]);
    }

    private function flatFileV2(): string
    {
        $cab = ['settlement-id', 'settlement-start-date', 'settlement-end-date', 'deposit-date', 'total-amount', 'currency', 'transaction-type', 'order-id', 'merchant-order-id', 'adjustment-id', 'shipment-id', 'marketplace-name', 'amount-type', 'amount-description', 'amount', 'fulfillment-id', 'posted-date', 'posted-date-time', 'order-item-code', 'merchant-order-item-id', 'merchant-adjustment-item-id', 'sku', 'quantity-purchased', 'promotion-id'];
        $linha = fn (string $tipo, string $pedido, string $at, string $ad, string $valor, string $sku = 'KZ-BAMBU', string $qtd = '') => implode("\t", ['123456', '', '', '', '', 'BRL', $tipo, $pedido, '', '', '', 'Amazon.com.br', $at, $ad, $valor, 'MFN', '2026-10-05', '', '', '', '', $sku, $qtd, '']);

        return implode("\n", [
            implode("\t", $cab),
            implode("\t", ['123456', '2026-09-28', '2026-10-12', '2026-10-14', '43.92', 'BRL'] + array_fill(0, 24, '')),
            $linha('Order', '701-8746900-0534617', 'ItemPrice', 'Principal', '55.90', 'KZ-BAMBU', '1'),
            $linha('Order', '701-8746900-0534617', 'ItemPrice', 'Shipping', '18.02'),
            $linha('Order', '701-8746900-0534617', 'Promotion', 'Principal', '-5.00'),
            $linha('Order', '701-8746900-0534617', 'ItemFees', 'Commission', '-8.39'),
            $linha('Order', '701-8746900-0534617', 'ItemFees', 'ShippingChargeback', '-18.02'),
            $linha('other-transaction', '', 'other-transaction', 'Subscription Fee', '1.41', ''),
        ])."\n";
    }

    public function test_sem_token_recusa(): void
    {
        $this->postJson('/api/amazon/reports/import')->assertStatus(401);
        $this->withHeader('Authorization', 'Bearer errado')->postJson('/api/amazon/reports/import')->assertStatus(401);
    }

    public function test_flat_file_v2_grava_taxa_real_confere_deposito_e_nao_duplica(): void
    {
        $pedido = $this->pedido('701-8746900-0534617');
        $arquivo = fn () => UploadedFile::fake()->createWithContent('amazon_liquidacao_123456.txt', $this->flatFileV2());

        $this->withHeader('Authorization', 'Bearer segredo-de-teste')
            ->post('/api/amazon/reports/import', ['file' => $arquivo()])
            ->assertOk()
            ->assertJsonPath('arquivos.0.importado.formato', 'liquidacao_v2')
            ->assertJsonPath('arquivos.0.importado.divergencias', [])
            ->assertJsonPath('arquivos.0.importado.taxas_reais', 1);

        // Venda 55,90 + frete 18,02 = 73,92; líquido 42,51; taxa 31,41.
        $this->assertDatabaseHas('order_channel_fees', [
            'order_id' => $pedido->id, 'channel' => 'amazon', 'source' => 'report',
            'gross_amount' => 73.92, 'payout_amount' => 42.51, 'fee_amount' => 31.41,
            'commission_fee' => 8.39, 'shipping_fee' => 18.02, 'seller_discount' => 5.00,
        ]);
        $this->assertDatabaseHas('marketplace_settlement_details', ['channel' => 'amazon', 'external_order_id' => '701-8746900-0534617', 'transaction_type' => 'Order', 'quantity' => 1, 'settlement_date' => '2026-10-05']);
        $this->assertDatabaseHas('amazon_report_uploads', ['status' => 'importado', 'tipo' => 'liquidacao_v2']);

        $this->withHeader('Authorization', 'Bearer segredo-de-teste')
            ->post('/api/amazon/reports/import', ['file' => $arquivo()])
            ->assertJsonPath('arquivos.0.duplicado', true);

        app(ImportadorRelatorioAmazon::class)->importar(Storage::disk('local')->path(DB::table('amazon_report_uploads')->value('caminho')), 'de-novo.txt');
        $this->assertSame(2, DB::table('marketplace_settlement_details')->count());
    }

    public function test_relatorio_por_datas_em_portugues_com_aviso_antes_do_cabecalho(): void
    {
        $pedido = $this->pedido('702-7019451-4524248');
        $csv = implode("\n", [
            '"Inclui transações da Amazon Marketplace, Logística da Amazon e Amazon Pay"',
            '"Todos os valores em BRL, a menos que especificado"',
            '"data/hora","id de liquidação","tipo","id do pedido","sku","descrição","quantidade","mercado","vendas de produtos","créditos de envio","descontos promocionais","tarifas de venda","outras tarifas de transação","outro","total"',
            '"8 de out. de 2026 10:52:14 GMT-3","999","Pedido","702-7019451-4524248","KZ-BAMBU","Cesto de bambu","1","amazon.com.br","55,90","18,02","0","-8,39","-18,02","0","47,51"',
            '"9 de out. de 2026 00:00:00 GMT-3","999","Tarifa de serviço","","","Tarifa de assinatura","","","0","0","0","0","0","-19,00","-19,00"',
        ]);

        $this->withHeader('Authorization', 'Bearer segredo-de-teste')
            ->post('/api/amazon/reports/import', ['file' => UploadedFile::fake()->createWithContent('amazon_transacoes.csv', $csv)])
            ->assertOk()
            ->assertJsonPath('arquivos.0.importado.formato', 'transacoes_datas')
            ->assertJsonPath('arquivos.0.importado.pedidos', 1);

        $this->assertDatabaseHas('order_channel_fees', ['order_id' => $pedido->id, 'gross_amount' => 73.92, 'payout_amount' => 47.51, 'fee_amount' => 26.41, 'source' => 'report']);
        $this->assertDatabaseHas('marketplace_settlement_details', ['channel' => 'amazon', 'statement_id' => '999', 'transaction_type' => 'Tarifa de serviço', 'external_sku_id' => 'Tarifa de assinatura', 'payout_amount' => -19.00, 'settlement_date' => '2026-10-09']);
    }

    public function test_relatorio_por_datas_em_ingles(): void
    {
        $pedido = $this->pedido('701-0913603-4733012');
        $csv = implode("\n", [
            '"Includes Amazon Marketplace, Fulfillment by Amazon (FBA), and Amazon Webstore transactions"',
            '"date/time","settlement id","type","order id","sku","description","quantity","marketplace","fulfillment","product sales","shipping credits","promotional rebates","selling fees","fba fees","other transaction fees","other","total"',
            '"Oct 8, 2026 2:50:14 PM GMT-03:00","555","Order","701-0913603-4733012","KZ-BAMBU","Cesto","1","amazon.com.br","Seller","1,055.90","18.02","-5.00","-80.39","0","-18.02","0","970.51"',
        ]);

        $r = app(ImportadorRelatorioAmazon::class)->importar($this->arquivoTemporario($csv), 'en.csv');

        $this->assertSame('2026-10-08', $r['ate']);
        $this->assertDatabaseHas('order_channel_fees', ['order_id' => $pedido->id, 'gross_amount' => 1073.92, 'payout_amount' => 970.51, 'fee_amount' => 103.41]);
    }

    public function test_formato_desconhecido_avisa_em_vermelho(): void
    {
        Notification::fake();
        User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->withHeader('Authorization', 'Bearer segredo-de-teste')
            ->post('/api/amazon/reports/import', ['file' => UploadedFile::fake()->createWithContent('qualquer.csv', "a,b\n1,2")])
            ->assertOk()
            ->assertJsonStructure(['arquivos' => [['erro_importacao']]]);

        Notification::assertSentTo(User::first(), AmazonRelatorioNotification::class,
            fn ($n, $canais, $quem) => $n->toArray($quem)['nivel'] === 'erro');
    }

    private function arquivoTemporario(string $conteudo): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'amz');
        file_put_contents($caminho, $conteudo);

        return $caminho;
    }
}
