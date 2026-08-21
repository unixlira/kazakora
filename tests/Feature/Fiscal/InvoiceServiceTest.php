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

    public function test_issue_skips_emission_for_mercado_livre_orders_and_marks_invoice_as_external(): void
    {
        $order = $this->makeOrder(['origin' => Order::ORIGIN_MERCADO_LIVRE, 'external_order_id' => 'ML-99']);

        $invoice = app(InvoiceService::class)->issue($order);

        $this->assertSame(Invoice::STATUS_EXTERNAL, $invoice->status);
        $this->assertNull($invoice->chave_acesso);
        $this->assertDatabaseHas('invoices', [
            'order_id' => $order->id,
            'status' => Invoice::STATUS_EXTERNAL,
        ]);
    }

    public function test_issue_is_idempotent_for_mercado_livre_orders_and_does_not_duplicate_the_invoice_row(): void
    {
        $order = $this->makeOrder(['origin' => Order::ORIGIN_MERCADO_LIVRE, 'external_order_id' => 'ML-100']);

        $service = app(InvoiceService::class);
        $first = $service->issue($order);
        $second = $service->issue($order->fresh());

        $this->assertSame($first->id, $second->id);
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
