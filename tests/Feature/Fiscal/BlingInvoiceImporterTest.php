<?php

namespace Tests\Feature\Fiscal;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Notifications\FiscalAlertNotification;
use App\Services\Bling\BlingInvoiceImporter;
use App\Services\NFe\NFeCertificateService;
use App\Services\NFe\NFeDanfeService;
use App\Services\NFe\NFeWebserviceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Mockery;
use NFePHP\Common\Certificate;
use Tests\TestCase;

/**
 * Pedido explícito 2026-09-02: "o kazakora tem que ter a nota que o bling
 * gerou tbm... deixar disponível pdf e xml". Desde essa data a NF-e do
 * TikTok Shop é emitida pelo Bling (único jeito do XML chegar ao canal e a
 * etiqueta liberar), então sem este importador o pedido ficaria sem
 * registro de nota nenhum deste lado.
 */
class BlingInvoiceImporterTest extends TestCase
{
    use RefreshDatabase;

    private const CHAVE = '35260965604590000107550020000017941284524944';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config([
            'services.bling.api_base_url' => 'https://api.bling.test/Api/v3',
            'services.bling.invoice_issuer_channels' => [Order::ORIGIN_TIKTOK_SHOP],
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

    private function makeOrder(): Order
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        return Order::create([
            'user_id' => $user->id,
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_TIKTOK_SHOP,
            'external_order_id' => '585846882986263720',
            'shipping_name' => 'Daniella Fachim',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '87780-000',
            'shipping_street' => 'Tocantins',
            'shipping_number' => '87',
            'shipping_neighborhood' => 'Tormena',
            'shipping_city' => 'Paraíso do Norte',
            'shipping_state' => 'PR',
            'subtotal' => 100,
            'total' => 100,
        ]);
    }

    public function test_authorized_invoice_issued_by_bling_is_saved_here_with_xml(): void
    {
        $xml = '<?xml version="1.0"?><nfeProc><NFe><infNFe Id="NFe'.self::CHAVE.'"></infNFe></NFe></nfeProc>';

        Http::fake([
            // A busca é em duas etapas: lista da loja e depois o pedido
            // por id (ver BlingOrderService::findByOrderNumber).
            '*/pedidos/vendas/26759086886' => Http::response(['data' => [
                'id' => 26759086886,
                'numeroLoja' => '585846882986263720',
                'notaFiscal' => ['id' => 55501],
                'situacao' => ['id' => 894762],
            ]]),
            '*/pedidos/vendas?*' => Http::response(['data' => [[
                'id' => 26759086886,
                'numeroLoja' => '585846882986263720',
            ]]]),
            '*/nfe/55501' => Http::response(['data' => [
                'id' => 55501,
                'numero' => '1795',
                'serie' => '1',
                'situacao' => 5,
                'chaveAcesso' => self::CHAVE,
                'valorNota' => 100.0,
            ]]),
            '*/nfe/documento/*' => Http::response(['data' => ['xml' => $xml]]),
        ]);

        $order = $this->makeOrder();

        $invoice = app(BlingInvoiceImporter::class)->syncForOrder($order);

        $this->assertNotNull($invoice, 'A nota emitida no Bling tem que existir aqui.');
        $this->assertSame(Invoice::STATUS_AUTHORIZED, $invoice->status);
        $this->assertSame(1795, $invoice->numero);
        $this->assertSame(self::CHAVE, $invoice->chave_acesso);
        $this->assertSame($order->id, $invoice->order_id);

        // Mesmo caminho das notas emitidas por nós — é o que faz as telas e
        // as rotas de download funcionarem sem exceção pra nota do Bling.
        $this->assertSame("invoices/{$order->id}/nfe-".self::CHAVE.'.xml', $invoice->xml_path);
        Storage::disk('local')->assertExists($invoice->xml_path);
        $this->assertSame($xml, Storage::disk('local')->get($invoice->xml_path));
    }

    public function test_note_cancelled_by_us_at_sefaz_is_not_revived_by_bling(): void
    {
        Http::fake([
            '*/pedidos/vendas/26759086886' => Http::response(['data' => [
                'id' => 26759086886, 'numeroLoja' => '585846882986263720', 'notaFiscal' => ['id' => 55501], 'situacao' => ['id' => 894762],
            ]]),
            '*/pedidos/vendas?*' => Http::response(['data' => [['id' => 26759086886, 'numeroLoja' => '585846882986263720']]]),
            '*/nfe/55501' => Http::response(['data' => [
                'id' => 55501, 'numero' => '1795', 'serie' => '3', 'situacao' => 5, 'chaveAcesso' => self::CHAVE, 'valorNota' => 100.0,
            ]]),
            '*/nfe/documento/*' => Http::response(['data' => ['xml' => '<nfeProc/>']]),
        ]);

        $order = $this->makeOrder();
        $order->update(['status' => Order::STATUS_CANCELLED]);
        Invoice::create([
            'order_id' => $order->id, 'serie' => 3, 'numero' => 1795, 'status' => Invoice::STATUS_CANCELLED, 'chave_acesso' => self::CHAVE,
            'origem' => Invoice::ORIGEM_PEDIDO, 'ambiente' => Invoice::AMBIENTE_PRODUCAO, 'autorizada_em' => now()->subHours(3),
            'protocolo_cancelamento' => '135269999999999', 'cancelada_em' => now(), 'valor_total' => 100,
        ]);

        app(BlingInvoiceImporter::class)->syncForOrder($order);

        $this->assertSame(Invoice::STATUS_CANCELLED, $order->invoice()->first()->status);
    }

    /**
     * Pedido do Lira em 22/10/2026: a série 3 (notas do Bling) usa o mesmo
     * DANFE da KazaKora que a série 2. O PDF do Bling nem é baixado.
     */
    public function test_pdf_of_bling_invoice_is_our_danfe_not_blings(): void
    {
        $xml = '<?xml version="1.0"?><nfeProc><NFe><infNFe Id="NFe'.self::CHAVE.'"></infNFe></NFe></nfeProc>';

        $this->mock(NFeDanfeService::class)
            ->shouldReceive('generate')->once()->with($xml)->andReturn('%PDF-danfe-kazakora');

        Http::fake([
            '*/pedidos/vendas/26759086886' => Http::response(['data' => [
                'id' => 26759086886,
                'numeroLoja' => '585846882986263720',
                'notaFiscal' => ['id' => 55501],
                'situacao' => ['id' => 894762],
            ]]),
            '*/pedidos/vendas?*' => Http::response(['data' => [[
                'id' => 26759086886,
                'numeroLoja' => '585846882986263720',
            ]]]),
            '*/nfe/55501' => Http::response(['data' => [
                'id' => 55501,
                'numero' => '1795',
                'serie' => '3',
                'situacao' => 5,
                'chaveAcesso' => self::CHAVE,
                'valorNota' => 100.0,
                'xml' => $xml,
                'linkPDF' => 'https://bling.test/pdf/55501',
            ]]),
            'bling.test/pdf/*' => Http::response('%PDF-do-bling'),
        ]);

        $order = $this->makeOrder();

        $invoice = app(BlingInvoiceImporter::class)->syncForOrder($order);

        $this->assertSame(3, $invoice->serie);
        $this->assertSame("invoices/{$order->id}/danfe-".self::CHAVE.'.pdf', $invoice->danfe_path);
        $this->assertSame('%PDF-danfe-kazakora', Storage::disk('local')->get($invoice->danfe_path));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'bling.test/pdf'));
    }

    /**
     * A emissão no Bling é assíncrona: no instante em que o pedido chega
     * pelo webhook, quase nunca existe nota. Isso não é erro — a varredura
     * invoices:sync-bling tenta de novo.
     */
    public function test_order_without_an_invoice_in_bling_yet_records_nothing(): void
    {
        Http::fake([
            '*/pedidos/vendas/26759086886' => Http::response(['data' => [
                'id' => 26759086886,
                'numeroLoja' => '585846882986263720',
                'notaFiscal' => ['id' => 0],
            ]]),
            '*/pedidos/vendas?*' => Http::response(['data' => [[
                'id' => 26759086886,
                'numeroLoja' => '585846882986263720',
            ]]]),
        ]);

        $order = $this->makeOrder();

        $this->assertNull(app(BlingInvoiceImporter::class)->syncForOrder($order));
        $this->assertNull($order->fresh()->invoice);
    }

    /**
     * BUG REAL 2026-09-02 (nota nº 2, pedido #1216): o Bling montou a nota
     * com CFOP 6108 numa venda interestadual e o emitente é MEI — a SEFAZ
     * recusou com "337 - CFOP inválido para emitente MEI". O CFOP certo
     * (6102) já estava no nosso cadastro o tempo todo. Conferir antes de
     * transmitir evita a rejeição, que custa conserto manual nota a nota.
     */
    public function test_invoice_with_a_cfop_that_diverges_from_our_catalog_is_not_sent_to_sefaz(): void
    {
        \App\Modules\Fiscal\Models\Company::create([
            'razao_social' => 'Kazakora', 'cnpj' => '65604590000107', 'regime_tributario' => 'mei',
            'zip' => '01000-000', 'street' => 'Rua X', 'number' => '1', 'neighborhood' => 'Centro',
            'city' => 'São Paulo', 'state' => 'SP',
        ]);

        $produto = \App\Modules\Catalog\Models\Product::factory()->create();
        \App\Modules\Fiscal\Models\ProductFiscalData::create([
            'product_id' => $produto->id, 'ncm' => '85076000', 'cfop' => '5102', 'cfop_outros_estados' => '6102',
        ]);
        \App\Modules\Marketplace\Models\ProductChannelListing::create([
            'product_id' => $produto->id, 'channel' => Order::ORIGIN_TIKTOK_SHOP, 'is_enabled' => true,
            'status' => \App\Modules\Marketplace\Models\ProductChannelListing::STATUS_PUBLISHED,
            'external_id' => '1736780989365585230',
        ]);

        config(['services.bling.auto_send_nfe' => true]);

        Http::fake([
            '*/pedidos/vendas/26759086886' => Http::response(['data' => [
                'id' => 26759086886, 'numeroLoja' => '585846882986263720', 'notaFiscal' => ['id' => 55501],
            ]]),
            '*/pedidos/vendas?*' => Http::response(['data' => [[
                'id' => 26759086886, 'numeroLoja' => '585846882986263720',
            ]]]),
            '*/nfe/55501' => Http::response(['data' => [
                'id' => 55501, 'numero' => '000002', 'serie' => 1, 'situacao' => 1, 'chaveAcesso' => '',
                'valorNota' => 79.98,
                // CFOP de venda a não contribuinte que MEI não pode usar.
                'itens' => [['codigo' => '1736780989365585230', 'cfop' => '6108', 'classificacaoFiscal' => '85076000']],
            ]]),
        ]);

        // Pedido pra outro estado (AM): o esperado é o CFOP interestadual, 6102.
        $order = $this->makeOrder();
        $order->update(['shipping_state' => 'AM']);

        \App\Models\User::factory()->create(['role' => \App\Models\User::ROLE_ADMIN]);
        \Illuminate\Support\Facades\Notification::fake();

        $invoice = app(BlingInvoiceImporter::class)->syncForOrder($order);
        // A varredura de 5 em 5 min passa de novo: a mesma divergência não avisa de novo.
        app(BlingInvoiceImporter::class)->syncForOrder($order);

        \Illuminate\Support\Facades\Notification::assertSentTimes(\App\Notifications\WebhookImportFailedNotification::class, 1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/enviar'));
        $this->assertSame(Invoice::STATUS_PENDING, $invoice->status, 'A nota fica pendente, não vai pra SEFAZ com CFOP errado.');
        $this->assertDatabaseHas('order_fulfillment_events', [
            'order_id' => $order->id,
            'status' => \App\Modules\Checkout\Models\OrderFulfillmentEvent::STATUS_FAILED,
        ]);
    }

    /**
     * Pedido + nota do Bling numa situação qualquer, com chave, e a SEFAZ
     * respondendo $cStat na consulta pela chave.
     */
    private function fakeNotaComChave(int $situacao, string $digestDoBling, string $cStat, string $digestAutorizado = 'DIGEST-AUTORIZADO='): void
    {
        $xmlBling = '<?xml version="1.0" encoding="UTF-8"?><nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00">'
            .'<NFe xmlns="http://www.portalfiscal.inf.br/nfe"><infNFe versao="4.00" Id="NFe'.self::CHAVE.'"><ide><dhEmi>2026-10-21T12:05:11-03:00</dhEmi></ide></infNFe>'
            .'<Signature xmlns="http://www.w3.org/2000/09/xmldsig#"><SignedInfo><Reference><DigestValue>'.$digestDoBling.'</DigestValue></Reference></SignedInfo></Signature>'
            .'</NFe></nfeProc>';

        Http::fake([
            '*/pedidos/vendas/26759086886' => Http::response(['data' => [
                'id' => 26759086886, 'numeroLoja' => '585846882986263720', 'notaFiscal' => ['id' => 55501],
            ]]),
            '*/pedidos/vendas?*' => Http::response(['data' => [[
                'id' => 26759086886, 'numeroLoja' => '585846882986263720',
            ]]]),
            '*/nfe/55501/enviar' => Http::response(['data' => []]),
            '*/nfe/55501' => Http::response(['data' => [
                'id' => 55501, 'numero' => '000556', 'serie' => 3, 'situacao' => $situacao,
                'chaveAcesso' => self::CHAVE, 'valorNota' => 100.0, 'dataEmissao' => '2026-10-21 12:05:11',
                'xml' => 'https://bling.test/xml/55501', 'itens' => [],
            ]]),
            'bling.test/xml/*' => Http::response($xmlBling),
        ]);

        $protocolo = in_array($cStat, ['100', '150'], true)
            ? '<protNFe versao="4.00"><infProt><tpAmb>1</tpAmb><chNFe>'.self::CHAVE.'</chNFe><dhRecbto>2026-10-21T11:59:32-03:00</dhRecbto>'
                .'<nProt>135264477322743</nProt><digVal>'.$digestAutorizado.'</digVal><cStat>'.$cStat.'</cStat><xMotivo>Autorizado o uso da NF-e</xMotivo></infProt></protNFe>'
            : '';
        $resposta = '<retConsSitNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><tpAmb>1</tpAmb><cStat>'.($protocolo ? '100' : $cStat).'</cStat>'
            .'<xMotivo>retorno</xMotivo><chNFe>'.self::CHAVE.'</chNFe>'.$protocolo.'</retConsSitNFe>';

        $certificado = Mockery::mock(NFeCertificateService::class);
        $certificado->shouldReceive('load')->andReturn(Mockery::mock(Certificate::class));
        $this->app->instance(NFeCertificateService::class, $certificado);

        $sefaz = Mockery::mock(NFeWebserviceService::class);
        $sefaz->shouldReceive('consultarChave')->with(self::CHAVE, Mockery::any())->andReturn($resposta);
        $this->app->instance(NFeWebserviceService::class, $sefaz);
    }

    /**
     * BUG REAL 2026-10-21 (#3325): a SEFAZ autorizou, o Bling ficou sem o
     * retorno e parou na situação 0. Quando o XML dele é o autorizado, o
     * protocolo da consulta completa o nfeProc e a nota vira autorizada aqui.
     */
    public function test_note_stuck_in_bling_situation_zero_is_recovered_from_sefaz(): void
    {
        $this->fakeNotaComChave(0, 'DIGEST-AUTORIZADO=', '100');

        $invoice = app(BlingInvoiceImporter::class)->syncForOrder($this->makeOrder());

        $this->assertSame(Invoice::STATUS_AUTHORIZED, $invoice->status);
        $this->assertSame('135264477322743', $invoice->protocolo_autorizacao);
        $this->assertSame('2026-10-21 11:59:32', $invoice->autorizada_em->format('Y-m-d H:i:s'));
        $xml = Storage::disk('local')->get($invoice->xml_path);
        $this->assertStringContainsString('<protNFe', $xml);
        $this->assertStringContainsString('<nProt>135264477322743</nProt>', $xml);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/enviar'));
    }

    /**
     * Os 10 casos reais de out/2026: o XML que o Bling guardou foi regerado
     * DEPOIS da autorização (outro DigestValue). Ele não é o documento
     * fiscal: fica pendente com o protocolo anotado e avisa, uma vez só.
     */
    public function test_regenerated_bling_xml_is_not_saved_as_the_authorized_note(): void
    {
        $this->fakeNotaComChave(0, 'DIGEST-REGERADO=', '100');
        User::factory()->create(['role' => User::ROLE_ADMIN]);
        Notification::fake();

        $order = $this->makeOrder();
        $invoice = app(BlingInvoiceImporter::class)->syncForOrder($order);
        Cache::flush();
        app(BlingInvoiceImporter::class)->syncForOrder($order);

        $this->assertSame(Invoice::STATUS_PENDING, $invoice->status);
        $this->assertNull($invoice->xml_path);
        $this->assertSame('135264477322743', $invoice->protocolo_autorizacao);
        $this->assertStringContainsString('não reenviar', $invoice->motivo_rejeicao);
        $this->assertSame('135264477322743', $order->fresh()->invoice->protocolo_autorizacao, 'A varredura seguinte não apaga o protocolo.');
        Notification::assertSentTimes(FiscalAlertNotification::class, 1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/enviar'));
    }

    /**
     * A causa: a nota ia ao /enviar de novo enquanto o Bling ainda estava em
     * 1 sem o retorno da 1ª autorização. Nota já autorizada na SEFAZ não é
     * enviada outra vez.
     */
    public function test_pending_note_already_authorized_at_sefaz_is_not_sent_again(): void
    {
        config(['services.bling.auto_send_nfe' => true]);
        $this->fakeNotaComChave(1, 'DIGEST-REGERADO=', '100');

        app(BlingInvoiceImporter::class)->syncForOrder($this->makeOrder());

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/enviar'));
    }

    public function test_pending_note_not_yet_at_sefaz_is_sent_once(): void
    {
        config(['services.bling.auto_send_nfe' => true]);
        $this->fakeNotaComChave(1, 'DIGEST-AUTORIZADO=', '217');

        app(BlingInvoiceImporter::class)->syncForOrder($this->makeOrder());

        $this->assertCount(1, Http::recorded(fn ($request) => str_contains($request->url(), '/enviar')));
    }

    /** Outro processo com a trava da mesma nota: este não envia. */
    public function test_note_being_sent_by_another_process_is_not_sent_twice(): void
    {
        config(['services.bling.auto_send_nfe' => true]);
        $this->fakeNotaComChave(1, 'DIGEST-AUTORIZADO=', '217');
        $trava = Cache::lock('bling.invoice.enviar.55501', 180);
        $trava->get();

        app(BlingInvoiceImporter::class)->syncForOrder($this->makeOrder());

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/enviar'));
        $trava->release();
    }

    /** Falha do Bling não pode derrubar o fluxo — o importador roda dentro do webhook. */
    public function test_failure_on_the_bling_side_never_throws(): void
    {
        Http::fake(['*' => Http::response(['error' => ['type' => 'SERVER_ERROR']], 500)]);

        $order = $this->makeOrder();

        $this->assertNull(app(BlingInvoiceImporter::class)->syncForOrder($order));
    }
}
