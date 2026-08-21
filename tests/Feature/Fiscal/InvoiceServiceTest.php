<?php

namespace Tests\Feature\Fiscal;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Services\InvoiceService;
use App\Services\NFe\NFeCertificateService;
use App\Services\NFe\NFeXmlBuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
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
     * Mudança 2026-08-21: pedido do Mercado Livre não fica mais parado num
     * stub STATUS_EXTERNAL — a conta foi reconfigurada pra emissão própria
     * (self-billing) e o Mercado Livre voltou a aceitar nota nossa via
     * `packs/{id}/fiscal_documents` (ver comentário em InvoiceService::issue()).
     * Segue o mesmo fluxo real de qualquer outro pedido: reserva
     * número/chave, monta o XML — sem certificado configurado, para em
     * PENDING (não lança, não faz sentido retry técnico esperar um
     * certificado aparecer sozinho).
     */
    public function test_issue_builds_a_real_pending_invoice_for_mercado_livre_orders(): void
    {
        Storage::fake('local');
        $order = $this->makeOrder(['origin' => Order::ORIGIN_MERCADO_LIVRE, 'external_order_id' => 'ML-99']);

        $certificateService = Mockery::mock(NFeCertificateService::class);
        $certificateService->shouldReceive('isConfigured')->once()->andReturn(false);
        $this->app->instance(NFeCertificateService::class, $certificateService);

        $invoice = app(InvoiceService::class)->issue($order);

        $this->assertSame(Invoice::STATUS_PENDING, $invoice->status);
        $this->assertNotNull($invoice->chave_acesso);
        $this->assertDatabaseHas('invoices', [
            'order_id' => $order->id,
            'status' => Invoice::STATUS_PENDING,
        ]);
    }

    /**
     * Pedido antigo que ficou com Invoice.status=STATUS_EXTERNAL (gravado
     * antes da mudança 2026-08-21) é convertido em pendente de verdade na
     * MESMA linha (order_id é unique) em vez de duplicar — cobre
     * convertExternalToPending().
     */
    public function test_issue_converts_a_legacy_external_invoice_into_a_real_pending_one(): void
    {
        Storage::fake('local');
        $order = $this->makeOrder(['origin' => Order::ORIGIN_MERCADO_LIVRE, 'external_order_id' => 'ML-100']);

        $legacy = Invoice::create([
            'order_id' => $order->id,
            'status' => Invoice::STATUS_EXTERNAL,
            'ambiente' => config('nfe.ambiente'),
            'serie' => 0,
            'numero' => $order->id,
            'valor_total' => $order->total,
        ]);

        $certificateService = Mockery::mock(NFeCertificateService::class);
        $certificateService->shouldReceive('isConfigured')->once()->andReturn(false);
        $this->app->instance(NFeCertificateService::class, $certificateService);

        $result = app(InvoiceService::class)->issue($order->fresh());

        $this->assertSame($legacy->id, $result->id);
        $this->assertSame(Invoice::STATUS_PENDING, $result->status);
        $this->assertNotSame(0, $result->serie);
        $this->assertNotNull($result->chave_acesso);
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
}
