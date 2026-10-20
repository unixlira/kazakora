<?php

namespace Tests\Feature\Fiscal;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Services\InvoiceService;
use App\Services\NFe\NFeCertificateService;
use App\Services\NFe\NFeDanfeService;
use App\Services\NFe\NFeWebserviceService;
use App\Services\NFe\NFeXmlBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use NFePHP\Common\Certificate;
use Tests\TestCase;

class InvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(array $attributes = []): Order
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        return Order::create(array_merge([
            'user_id' => $user->id,
            'status' => Order::STATUS_PAID,
            'origin' => Order::ORIGIN_STORE,
            'shipping_name' => 'Cliente',
            'shipping_phone' => '11999999999',
            'shipping_zip' => '01000-000',
            'shipping_street' => 'Rua X',
            'shipping_number' => '1',
            'shipping_neighborhood' => 'Centro',
            'shipping_city' => 'São Paulo',
            'shipping_state' => 'SP',
            'subtotal' => 100,
            'total' => 100,
        ], $attributes));
    }

    /**
     * Desde 2026-08-22 (commit c4e950b, pedido explícito do usuário) pedido
     * do Mercado Livre segue o MESMO fluxo de emissão própria de qualquer
     * outro canal — a conta ML foi reconfigurada pra emissor próprio e o
     * envio fica travado lá (invoice_pending) sem a nossa nota. Antes disto
     * os testes aqui esperavam STATUS_EXTERNAL (ML emitindo a nota), regra
     * que deixou de valer.
     */
    private function fakeXmlWithoutCertificate(): void
    {
        Storage::fake('local');

        $xmlBuilder = Mockery::mock(NFeXmlBuilderService::class);
        $xmlBuilder->shouldReceive('build')->andReturn(['xml' => '<xml>ml</xml>', 'chave' => str_repeat('5', 44)]);
        $this->app->instance(NFeXmlBuilderService::class, $xmlBuilder);

        // Corta antes de signAndSend — aqui só interessa que a nota própria
        // foi reservada (pendente), não o envio à SEFAZ.
        $certificateService = Mockery::mock(NFeCertificateService::class);
        $certificateService->shouldReceive('isConfigured')->andReturn(false);
        $this->app->instance(NFeCertificateService::class, $certificateService);
    }

    public function test_issue_emits_our_own_invoice_for_mercado_livre_orders_instead_of_marking_it_external(): void
    {
        $this->fakeXmlWithoutCertificate();
        $order = $this->makeOrder(['origin' => Order::ORIGIN_MERCADO_LIVRE, 'external_order_id' => 'ML-99']);

        $invoice = app(InvoiceService::class)->issue($order);

        $this->assertSame(Invoice::STATUS_PENDING, $invoice->status);
        $this->assertSame(str_repeat('5', 44), $invoice->chave_acesso);
        $this->assertDatabaseMissing('invoices', [
            'order_id' => $order->id,
            'status' => Invoice::STATUS_EXTERNAL,
        ]);
    }

    public function test_issue_is_idempotent_for_mercado_livre_orders_and_does_not_duplicate_the_invoice_row(): void
    {
        $this->fakeXmlWithoutCertificate();
        $order = $this->makeOrder(['origin' => Order::ORIGIN_MERCADO_LIVRE, 'external_order_id' => 'ML-100']);

        $service = app(InvoiceService::class);
        $first = $service->issue($order);
        $second = $service->issue($order->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->numero, $second->numero, 'retry nunca reserva outro número de NF-e');
        $this->assertSame(1, Invoice::query()->where('order_id', $order->id)->count());
    }

    /**
     * BUG REAL 2026-08-10 (pedido #215): rejeição da SEFAZ (qualquer cStat
     * fora 100/110/301/302, ex: "778 - NCM inexistente") é um erro de
     * DADO, corrigível — a SEFAZ nunca registrou aquela chave de verdade.
     * Tratar REJECTED como resposta definitiva (mesmo grupo de AUTHORIZED)
     * fazia o retry que já existe em
     * ProductFiscalController::retryStuckInvoices() nunca ter efeito
     * nenhum depois de corrigir o cadastro do produto — a nota ficava
     * rejeitada pra sempre. Reaproveita o MESMO número já reservado (nunca
     * pula número de NF-e por causa de uma rejeição corrigível).
     */
    public function test_issue_retries_a_rejected_invoice_reusing_the_same_reserved_number(): void
    {
        Storage::fake('local');
        $order = $this->makeOrder();

        $invoice = Invoice::create([
            'order_id' => $order->id,
            'status' => Invoice::STATUS_REJECTED,
            'serie' => 1,
            'numero' => 42,
            'ambiente' => 'homologacao',
            'chave_acesso' => str_repeat('1', 44),
            'xml_path' => "invoices/{$order->id}/nfe-old.xml",
            'motivo_rejeicao' => '778 - Rejeição: Informado NCM inexistente [nItem:1]',
        ]);
        Storage::disk('local')->put($invoice->xml_path, '<xml>antigo</xml>');

        $novaChave = str_repeat('2', 44);

        $xmlBuilder = Mockery::mock(NFeXmlBuilderService::class);
        $xmlBuilder->shouldReceive('build')
            ->once()
            ->with(Mockery::on(fn ($o) => $o->id === $order->id), 42)
            ->andReturn(['xml' => '<xml>novo</xml>', 'chave' => $novaChave]);
        $this->app->instance(NFeXmlBuilderService::class, $xmlBuilder);

        // Corta logo antes de signAndSend (já coberto por outro fluxo) —
        // aqui só interessa confirmar que o rebuild aconteceu.
        $certificateService = Mockery::mock(NFeCertificateService::class);
        $certificateService->shouldReceive('isConfigured')->once()->andReturn(false);
        $this->app->instance(NFeCertificateService::class, $certificateService);

        $result = app(InvoiceService::class)->issue($order->fresh());

        $this->assertSame(42, $result->numero);
        $this->assertSame($novaChave, $result->chave_acesso);
        Storage::disk('local')->assertMissing("invoices/{$order->id}/nfe-old.xml");
        Storage::disk('local')->assertExists($result->xml_path);
    }

    /**
     * BUG REAL 2026-09-07 (pedido #1604, "a nota da Amanda não saiu"):
     * rejeição **539 — Duplicidade de NF-e** é a exceção da regra acima. A
     * SEFAZ está dizendo que aquele número JÁ foi consumido por outra
     * chave, então reenviar com o mesmo número é rejeição garantida pra
     * sempre — o pedido ficou preso nesse laço, e como a Shopee só libera o
     * envio depois da nota, a etiqueta nunca saiu.
     */
    public function test_issue_reserves_a_new_number_when_the_rejection_was_a_duplicate(): void
    {
        Storage::fake('local');
        $order = $this->makeOrder();

        $invoice = Invoice::create([
            'order_id' => $order->id,
            'status' => Invoice::STATUS_REJECTED,
            'serie' => (int) config('nfe.serie'),
            'numero' => 2037,
            'ambiente' => config('nfe.ambiente'),
            'chave_acesso' => str_repeat('1', 44),
            'xml_path' => "invoices/{$order->id}/nfe-old.xml",
            'motivo_rejeicao' => '539 - Rejeição: Duplicidade de NF-e com diferença na Chave de Acesso [chNFe:352609...]',
        ]);
        Storage::disk('local')->put($invoice->xml_path, '<xml>antigo</xml>');

        $xmlBuilder = Mockery::mock(NFeXmlBuilderService::class);
        $xmlBuilder->shouldReceive('build')
            ->atLeast()->once()
            // O número NOVO, nunca o 2037 queimado.
            ->with(Mockery::on(fn ($o) => $o->id === $order->id), 2038)
            ->andReturn(['xml' => '<xml>novo</xml>', 'chave' => str_repeat('3', 44)]);
        $this->app->instance(NFeXmlBuilderService::class, $xmlBuilder);

        $certificateService = Mockery::mock(NFeCertificateService::class);
        $certificateService->shouldReceive('isConfigured')->andReturn(false);
        $this->app->instance(NFeCertificateService::class, $certificateService);

        $admin = \App\Models\User::factory()->create(['role' => \App\Models\User::ROLE_ADMIN]);

        $result = app(InvoiceService::class)->issue($order->fresh());

        $this->assertSame(2038, $result->numero);
        $this->assertSame(Invoice::STATUS_PENDING, $result->status);
        $this->assertNull($result->motivo_rejeicao);
        Storage::disk('local')->assertMissing("invoices/{$order->id}/nfe-old.xml");

        // O 2037 pulado fica registrado (painel + fechamento do mês) e o
        // admin é avisado no sininho: a nota 2037 de alguém falta no sistema.
        $this->assertDatabaseHas('nfe_numeracao_ocorrencias', [
            'tipo' => 'duplicidade', 'numero_inicial' => 2037, 'order_id' => $order->id, 'resolvido_em' => null,
        ]);
        $this->assertStringContainsString('2037', $admin->notifications()->first()->data['message']);
    }

    /**
     * A CAUSA do 539 acima: o contador de números olhava só a coluna
     * `numero`, e a coluna mente — o BlingInvoiceImporter renumerava uma
     * nota que já tinha sido autorizada pela SEFAZ, trocando pela numeração
     * interna do Bling (o #1603 saiu como série 2 nº 2037 e virou "série 3
     * nº 119" no banco). A chave de acesso não mente: série e número estão
     * gravados dentro dela.
     */
    public function test_the_next_number_respects_what_is_written_inside_the_access_key(): void
    {
        Storage::fake('local');

        $serie = str_pad((string) config('nfe.serie'), 3, '0', STR_PAD_LEFT);
        // Chave real de uma nota nº 2037 da série configurada, gravada numa
        // linha cuja COLUNA foi renumerada pra série 3 / nº 119.
        $chaveDoVizinho = '35260965604590000107'.'55'.$serie.'000002037'.'1'.'70524178'.'1';

        Invoice::create([
            'order_id' => $this->makeOrder()->id,
            'status' => Invoice::STATUS_AUTHORIZED,
            'serie' => 3,
            'numero' => 119,
            'ambiente' => config('nfe.ambiente'),
            'chave_acesso' => $chaveDoVizinho,
        ]);

        $order = $this->makeOrder();

        $xmlBuilder = Mockery::mock(NFeXmlBuilderService::class);
        $xmlBuilder->shouldReceive('build')
            ->atLeast()->once()
            // 2038, não 2037: o 2037 está queimado dentro da chave acima.
            ->with(Mockery::on(fn ($o) => $o->id === $order->id), 2038)
            ->andReturn(['xml' => '<xml>novo</xml>', 'chave' => str_repeat('4', 44)]);
        $this->app->instance(NFeXmlBuilderService::class, $xmlBuilder);

        $certificateService = Mockery::mock(NFeCertificateService::class);
        $certificateService->shouldReceive('isConfigured')->andReturn(false);
        $this->app->instance(NFeCertificateService::class, $certificateService);

        $this->assertSame(2038, app(InvoiceService::class)->issue($order)->numero);
    }

    /**
     * DENIED (cStat 110/301/302 — normalmente irregularidade de CNPJ/IE do
     * emissor) continua terminal de propósito, ao contrário de REJECTED:
     * a SEFAZ queima esse número, reenviar o mesmo dado nunca muda o
     * resultado sem intervenção manual fora do sistema.
     */
    public function test_issue_does_not_retry_a_denied_invoice(): void
    {
        $order = $this->makeOrder();

        $invoice = Invoice::create([
            'order_id' => $order->id,
            'status' => Invoice::STATUS_DENIED,
            'serie' => 1,
            'numero' => 7,
            'ambiente' => 'homologacao',
            'motivo_rejeicao' => '301 - Uso Denegado: Irregularidade fiscal do emitente',
        ]);

        $xmlBuilder = Mockery::mock(NFeXmlBuilderService::class);
        $xmlBuilder->shouldNotReceive('build');
        $this->app->instance(NFeXmlBuilderService::class, $xmlBuilder);

        $result = app(InvoiceService::class)->issue($order->fresh());

        $this->assertSame($invoice->id, $result->id);
        $this->assertSame(Invoice::STATUS_DENIED, $result->status);
    }

    /**
     * BUG REAL 2026-10-19 (pedido #3279, Rubens): a 1ª tentativa caiu em
     * "Empty reply from server" com a nota 2-3154 já autorizada na SEFAZ.
     * O retry reenviou a mesma chave, voltou 204 (duplicidade da MESMA
     * chave), o 204 foi tratado como 539 e o pedido ganhou a nota 2-3156.
     */
    private const CHAVE_3154 = '35261065604590000107550020000031541965208610';

    private function notaAssinada(): string
    {
        return '<NFe xmlns="http://www.portalfiscal.inf.br/nfe"><infNFe Id="NFe'.self::CHAVE_3154.'" versao="4.00"><ide><nNF>3154</nNF></ide></infNFe>'
            .'<Signature xmlns="http://www.w3.org/2000/09/xmldsig#"><SignedInfo><Reference><DigestValue>T22uRleIhvSw1BYDjgYRF8Hg7x0=</DigestValue></Reference></SignedInfo></Signature></NFe>';
    }

    private function consultaAutorizada(): string
    {
        return '<retConsSitNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><cStat>100</cStat><xMotivo>Autorizado o uso da NF-e</xMotivo>'
            .'<protNFe versao="4.00"><infProt><tpAmb>1</tpAmb><chNFe>'.self::CHAVE_3154.'</chNFe><dhRecbto>2026-10-19T17:26:20-03:00</dhRecbto>'
            .'<nProt>135264443167997</nProt><digVal>T22uRleIhvSw1BYDjgYRF8Hg7x0=</digVal><cStat>100</cStat><xMotivo>Autorizado o uso da NF-e</xMotivo></infProt></protNFe></retConsSitNFe>';
    }

    private function notaDoRubens(string $status, ?string $motivo = null): array
    {
        Storage::fake('local');
        $order = $this->makeOrder(['origin' => Order::ORIGIN_SHOPEE]);

        $invoice = Invoice::create([
            'order_id' => $order->id,
            'status' => $status,
            'serie' => 2,
            'numero' => 3154,
            'ambiente' => config('nfe.ambiente'),
            'chave_acesso' => self::CHAVE_3154,
            'xml_path' => "invoices/{$order->id}/nfe-".self::CHAVE_3154.'.xml',
            'motivo_rejeicao' => $motivo,
        ]);
        Storage::disk('local')->put($invoice->xml_path, $this->notaAssinada());

        $xmlBuilder = Mockery::mock(NFeXmlBuilderService::class);
        $xmlBuilder->shouldNotReceive('build');
        $this->app->instance(NFeXmlBuilderService::class, $xmlBuilder);

        $certificateService = Mockery::mock(NFeCertificateService::class);
        $certificateService->shouldReceive('isConfigured')->andReturn(true);
        $certificateService->shouldReceive('load')->andReturn(Mockery::mock(Certificate::class));
        $this->app->instance(NFeCertificateService::class, $certificateService);

        $danfe = Mockery::mock(NFeDanfeService::class);
        $danfe->shouldReceive('generate')->andReturn('%PDF');
        $this->app->instance(NFeDanfeService::class, $danfe);

        return [$order, $invoice];
    }

    private function assertAdotouANota3154(Invoice $result, Invoice $invoice): void
    {
        $this->assertSame($invoice->id, $result->id);
        $this->assertSame(Invoice::STATUS_AUTHORIZED, $result->status);
        $this->assertSame(3154, (int) $result->numero);
        $this->assertSame(self::CHAVE_3154, $result->chave_acesso);
        $this->assertSame('135264443167997', $result->protocolo_autorizacao);
        $this->assertNull($result->motivo_rejeicao);
        $this->assertDatabaseCount('nfe_numeracao_ocorrencias', 0);
        $this->assertStringContainsString('<nProt>135264443167997</nProt>', Storage::disk('local')->get($result->xml_path));
    }

    public function test_a_204_rejection_adopts_the_authorized_key_instead_of_issuing_a_second_invoice(): void
    {
        [$order, $invoice] = $this->notaDoRubens(Invoice::STATUS_REJECTED, '204 - Rejeição: Duplicidade de NF-e');

        $sefaz = Mockery::mock(NFeWebserviceService::class);
        $sefaz->shouldReceive('consultarChave')->once()->with(self::CHAVE_3154, Mockery::any())->andReturn($this->consultaAutorizada());
        $sefaz->shouldNotReceive('enviarESincronizar');
        $this->app->instance(NFeWebserviceService::class, $sefaz);

        $this->assertAdotouANota3154(app(InvoiceService::class)->issue($order->fresh()), $invoice);
    }

    public function test_a_signed_invoice_whose_send_failed_is_checked_at_sefaz_before_resending(): void
    {
        [$order, $invoice] = $this->notaDoRubens(Invoice::STATUS_SIGNED);

        $sefaz = Mockery::mock(NFeWebserviceService::class);
        $sefaz->shouldReceive('consultarChave')->once()->andReturn($this->consultaAutorizada());
        $sefaz->shouldNotReceive('enviarESincronizar');
        $this->app->instance(NFeWebserviceService::class, $sefaz);

        $this->assertAdotouANota3154(app(InvoiceService::class)->issue($order->fresh()), $invoice);
    }

    public function test_a_204_answer_on_send_is_resolved_on_the_spot(): void
    {
        // Pendente (não passa pela consulta prévia) e com o XML já gerado:
        // o rebuild devolve o mesmo XML, como na reemissão de uma nota
        // cuja tentativa anterior não ficou registrada.
        [$order, $invoice] = $this->notaDoRubens(Invoice::STATUS_PENDING);
        $xmlBuilder = Mockery::mock(NFeXmlBuilderService::class);
        $xmlBuilder->shouldReceive('build')->andReturn(['xml' => $this->notaAssinada(), 'chave' => self::CHAVE_3154]);
        $this->app->instance(NFeXmlBuilderService::class, $xmlBuilder);

        $sefaz = Mockery::mock(NFeWebserviceService::class);
        $sefaz->shouldReceive('sign')->andReturnUsing(fn ($xml) => $xml);
        $sefaz->shouldReceive('enviarESincronizar')->once()->andReturn(
            '<retEnviNFe xmlns="http://www.portalfiscal.inf.br/nfe"><cStat>104</cStat><xMotivo>Lote processado</xMotivo><protNFe versao="4.00"><infProt>'
            .'<chNFe>'.self::CHAVE_3154.'</chNFe><cStat>204</cStat><xMotivo>Rejeição: Duplicidade de NF-e</xMotivo></infProt></protNFe></retEnviNFe>'
        );
        $sefaz->shouldReceive('consultarChave')->once()->andReturn($this->consultaAutorizada());
        $this->app->instance(NFeWebserviceService::class, $sefaz);

        $this->assertAdotouANota3154(app(InvoiceService::class)->issue($order->fresh()), $invoice);
    }

    public function test_a_signed_invoice_that_never_reached_sefaz_is_resent_with_the_same_number(): void
    {
        [$order, $invoice] = $this->notaDoRubens(Invoice::STATUS_SIGNED);
        $xmlBuilder = Mockery::mock(NFeXmlBuilderService::class);
        $xmlBuilder->shouldReceive('build')->once()->with(Mockery::any(), 3154)->andReturn(['xml' => '<xml>nova</xml>', 'chave' => self::CHAVE_3154]);
        $this->app->instance(NFeXmlBuilderService::class, $xmlBuilder);

        $sefaz = Mockery::mock(NFeWebserviceService::class);
        $sefaz->shouldReceive('consultarChave')->once()->andReturn(
            '<retConsSitNFe xmlns="http://www.portalfiscal.inf.br/nfe"><cStat>217</cStat><xMotivo>Rejeição: NF-e não consta na base de dados da SEFAZ</xMotivo></retConsSitNFe>'
        );
        $sefaz->shouldReceive('sign')->once()->andReturn('<xml>assinada</xml>');
        $sefaz->shouldReceive('enviarESincronizar')->once()->andThrow(new \RuntimeException('Empty reply from server'));
        $this->app->instance(NFeWebserviceService::class, $sefaz);

        try {
            app(InvoiceService::class)->issue($order->fresh());
            $this->fail('Esperava a falha de comunicação.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Empty reply from server', $e->getMessage());
        }

        // Mesmo número, e fica `signed` pra próxima rodada consultar de novo.
        $this->assertSame(3154, (int) $invoice->fresh()->numero);
        $this->assertSame(Invoice::STATUS_SIGNED, $invoice->fresh()->status);
    }
}
