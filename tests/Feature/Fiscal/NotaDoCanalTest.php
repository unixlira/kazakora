<?php

namespace Tests\Feature\Fiscal;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Support\CancelamentoAutomaticoDaNota;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Services\Bling\BlingInvoiceImporter;
use App\Services\NFe\NFeCertificateService;
use App\Services\NFe\NFeWebserviceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use NFePHP\Common\Certificate;
use Tests\TestCase;

/**
 * Lira 2026-11-03 (#3779: Bling 3-655 em dobro com a TikTok 6-568): a nota
 * do TikTok fica e passa a aparecer no pedido; a do Bling é cancelada e
 * fica no histórico com chave, número, flag de cancelada e XML. O histórico
 * da nota cancelada vale para qualquer venda.
 */
class NotaDoCanalTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE_BLING = '35261165604590000107550030000006551932493546';

    private const CHAVE_TIKTOK = '35261165604590000107550060000005681000000017';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config([
            'services.bling.api_base_url' => 'https://api.bling.test/Api/v3',
            'services.bling.invoice_issuer_channels' => [Order::ORIGIN_TIKTOK_SHOP],
            'services.bling.auto_send_nfe' => true,
        ]);

        MarketplaceAccount::create([
            'channel' => MarketplaceAccount::CHANNEL_BLING,
            'status' => MarketplaceAccount::STATUS_CONNECTED,
            'access_token' => 'token-de-teste',
            'refresh_token' => 'refresh-de-teste',
            'token_expires_at' => now()->addHour(),
            'metadata' => ['tiktok_loja_id' => 206277670],
        ]);
    }

    private function pedido(): Order
    {
        return Order::create([
            'user_id' => User::factory()->create(['role' => User::ROLE_CUSTOMER])->id,
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_TIKTOK_SHOP,
            'external_order_id' => '586953092546856356',
            'shipping_name' => 'Cliente', 'shipping_phone' => '11999999999', 'shipping_zip' => '01310-100',
            'shipping_street' => 'Rua', 'shipping_number' => '1', 'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo', 'shipping_state' => 'SP',
            'subtotal' => 399.92, 'total' => 399.92,
        ]);
    }

    private function notaDoBling(Order $pedido, string $status = Invoice::STATUS_AUTHORIZED): Invoice
    {
        Storage::disk('local')->put("invoices/{$pedido->id}/nfe-".self::CHAVE_BLING.'.xml', '<nfeProc/>');

        return Invoice::create([
            'order_id' => $pedido->id, 'serie' => 3, 'numero' => 655, 'status' => $status, 'valor_total' => 399.92,
            'chave_acesso' => self::CHAVE_BLING, 'protocolo_autorizacao' => '135264686865567', 'autorizada_em' => now()->subMinutes(15),
            'xml_path' => "invoices/{$pedido->id}/nfe-".self::CHAVE_BLING.'.xml',
        ]);
    }

    private function sefazAutoriza(): void
    {
        $certificado = Mockery::mock(NFeCertificateService::class);
        $certificado->shouldReceive('load')->andReturn(Mockery::mock(Certificate::class));
        $this->app->instance(NFeCertificateService::class, $certificado);

        $sefaz = Mockery::mock(NFeWebserviceService::class);
        $sefaz->shouldReceive('consultarChave')->andReturn(
            '<retConsSitNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><cStat>100</cStat><chNFe>'.self::CHAVE_TIKTOK.'</chNFe>'
            .'<protNFe versao="4.00"><infProt><chNFe>'.self::CHAVE_TIKTOK.'</chNFe><dhRecbto>2026-11-03T13:15:00-03:00</dhRecbto>'
            .'<nProt>135264680000001</nProt><cStat>100</cStat></infProt></protNFe></retConsSitNFe>'
        );
        $this->app->instance(NFeWebserviceService::class, $sefaz);
    }

    private function historico(Order $pedido)
    {
        return OrderFulfillmentEvent::query()->where('order_id', $pedido->id)->where('step', OrderFulfillmentEvent::STEP_INVOICE_CANCELLED)->get();
    }

    public function test_nota_cancelada_fica_no_historico_com_chave_numero_e_xml(): void
    {
        $pedido = $this->pedido();
        $nota = $this->notaDoBling($pedido);

        $nota->update(['status' => Invoice::STATUS_CANCELLED, 'cancelada_em' => now(), 'protocolo_cancelamento' => '135264690000002']);
        $nota->update(['motivo_cancelamento' => 'Nota em dobro']);
        $nota->update(['status' => Invoice::STATUS_CANCELLED]);

        $eventos = $this->historico($pedido);
        $this->assertCount(1, $eventos);
        $this->assertStringContainsString('NF-e 3-655 gerada na plataforma Bling e cancelada em seguida', $eventos[0]->message);
        $this->assertTrue($eventos[0]->context['cancelada']);
        $this->assertSame(self::CHAVE_BLING, $eventos[0]->context['chave']);
        $this->assertSame(655, $eventos[0]->context['numero']);
        $this->assertSame("invoices/{$pedido->id}/nfe-".self::CHAVE_BLING.'.xml', $eventos[0]->context['xml_path']);
    }

    public function test_nota_do_bling_sem_xml_guardado_baixa_o_xml_do_bling(): void
    {
        Http::fake(['*/nfe/documento/*' => Http::response(['data' => ['xml' => '<nfeProc>bling</nfeProc>']])]);

        $pedido = $this->pedido();
        $nota = $this->notaDoBling($pedido);
        Storage::disk('local')->delete($nota->xml_path);
        $nota->update(['xml_path' => null]);

        $nota->update(['status' => Invoice::STATUS_CANCELLED]);

        $caminho = $this->historico($pedido)->first()->context['xml_path'];
        $this->assertSame('<nfeProc>bling</nfeProc>', Storage::disk('local')->get($caminho));
    }

    public function test_comando_registra_nota_do_tiktok_e_guarda_a_do_bling_cancelada(): void
    {
        $this->sefazAutoriza();
        $pedido = $this->pedido();
        $this->notaDoBling($pedido, Invoice::STATUS_CANCELLED);

        $this->artisan('nfe:nota-do-canal', ['pedido' => $pedido->id, 'chave' => self::CHAVE_TIKTOK, '--valor' => '499,90', '--aplicar' => true])
            ->assertSuccessful();

        $nota = $pedido->invoice()->first();
        $this->assertSame(Invoice::EMITIDA_TIKTOK, $nota->emitida_por);
        $this->assertSame(Invoice::STATUS_AUTHORIZED, $nota->status);
        $this->assertSame(6, $nota->serie);
        $this->assertSame(568, $nota->numero);
        $this->assertSame('499.90', $nota->valor_total);
        $this->assertSame('135264680000001', $nota->protocolo_autorizacao);
        $this->assertNull($nota->cancelada_em);

        $this->assertSame(self::CHAVE_BLING, $this->historico($pedido)->sole()->context['chave']);
    }

    public function test_comando_nao_mexe_em_pedido_com_outra_nota_autorizada(): void
    {
        $this->sefazAutoriza();
        $pedido = $this->pedido();
        $this->notaDoBling($pedido);

        $this->artisan('nfe:nota-do-canal', ['pedido' => $pedido->id, 'chave' => self::CHAVE_TIKTOK, '--valor' => '499,90', '--aplicar' => true])
            ->assertFailed();

        $this->assertSame(3, $pedido->invoice()->first()->serie);
    }

    public function test_simulacao_nao_grava(): void
    {
        $this->sefazAutoriza();
        $pedido = $this->pedido();
        $this->notaDoBling($pedido, Invoice::STATUS_CANCELLED);

        $this->artisan('nfe:nota-do-canal', ['pedido' => $pedido->id, 'chave' => self::CHAVE_TIKTOK, '--valor' => '499,90'])->assertSuccessful();

        $this->assertSame(3, $pedido->invoice()->first()->serie);
    }

    private function fakeBling(int $situacao): void
    {
        Http::fake([
            '*/pedidos/vendas/26800000001' => Http::response(['data' => [
                'id' => 26800000001, 'numeroLoja' => '586953092546856356', 'notaFiscal' => ['id' => 77701], 'situacao' => ['id' => 1],
            ]]),
            '*/pedidos/vendas?*' => Http::response(['data' => [['id' => 26800000001, 'numeroLoja' => '586953092546856356']]]),
            '*/nfe/77701/enviar' => Http::response(['data' => []]),
            '*/nfe/77701' => Http::response(['data' => [
                'id' => 77701, 'numero' => '000655', 'serie' => 3, 'situacao' => $situacao, 'chaveAcesso' => self::CHAVE_BLING, 'valorNota' => 399.92, 'itens' => [],
            ]]),
            '*/nfe/documento/*' => Http::response(['data' => ['xml' => '<nfeProc>bling</nfeProc>']]),
        ]);
    }

    private function notaDoTikTok(Order $pedido): Invoice
    {
        return Invoice::create([
            'order_id' => $pedido->id, 'emitida_por' => Invoice::EMITIDA_TIKTOK, 'serie' => 6, 'numero' => 568,
            'status' => Invoice::STATUS_AUTHORIZED, 'valor_total' => 499.90, 'chave_acesso' => self::CHAVE_TIKTOK,
            'protocolo_autorizacao' => '135264680000001', 'autorizada_em' => now()->subMinutes(30),
        ]);
    }

    public function test_sync_do_bling_nao_sobrescreve_a_nota_do_tiktok_e_guarda_a_cancelada(): void
    {
        $this->fakeBling(2);
        $pedido = $this->pedido();
        $this->notaDoTikTok($pedido);

        app(BlingInvoiceImporter::class)->syncForOrder($pedido);

        $nota = $pedido->invoice()->first();
        $this->assertSame(6, $nota->serie);
        $this->assertSame(Invoice::STATUS_AUTHORIZED, $nota->status);
        $this->assertSame(self::CHAVE_BLING, $this->historico($pedido)->sole()->context['chave']);
    }

    public function test_nota_do_bling_pendente_nao_e_transmitida_quando_o_tiktok_ja_emitiu(): void
    {
        $this->fakeBling(1);
        $pedido = $this->pedido();
        $this->notaDoTikTok($pedido);

        app(BlingInvoiceImporter::class)->syncForOrder($pedido);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/enviar'));
        $this->assertSame(6, $pedido->invoice()->first()->serie);
        $this->assertTrue(OrderFulfillmentEvent::query()->where('order_id', $pedido->id)->where('status', OrderFulfillmentEvent::STATUS_FAILED)->exists());
    }

    public function test_cancelamento_automatico_nao_pega_nota_do_tiktok(): void
    {
        $pedido = $this->pedido();
        $pedido->update(['status' => Order::STATUS_CANCELLED]);
        $this->notaDoTikTok($pedido);

        $this->assertCount(0, app(CancelamentoAutomaticoDaNota::class)->casos($pedido->id));
    }
}
