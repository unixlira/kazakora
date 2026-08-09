<?php

namespace Tests\Feature\Fiscal;

use App\Models\User;
use App\Modules\Checkout\Jobs\SendOrderReceiptEmailJob;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Models\InvoiceGenerationLog;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Fiscal\Services\InvoiceService;
use App\Modules\Marketplace\Jobs\ConfirmChannelShippingJob;
use App\Modules\Marketplace\Jobs\SubmitInvoiceToChannelJob;
use App\Modules\Marketplace\Support\OrderImportService;
use App\Notifications\InvoiceIssuanceFailedNotification;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Chama GenerateInvoiceJob::handle()/failed() como métodos diretos (não via
 * dispatch/dispatchSync) — ->dispatchSync() sob Queue::fake() nunca chega a
 * executar handle() de verdade (só registra o push fake), e o driver "sync"
 * real trata qualquer exceção como tentativa esgotada na hora, o que
 * impediria de isolar o comportamento "ainda restam tentativas" vs "última
 * tentativa" que este job precisa distinguir.
 */
class GenerateInvoiceJobTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(): Order
    {
        $user = User::factory()->create(['role' => User::ROLE_CUSTOMER]);

        return Order::create([
            'user_id' => $user->id,
            'status' => Order::STATUS_PAID,
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
        ]);
    }

    private function fakeQueueJobWithAttempts(int $attempts): QueueJobContract
    {
        $queueJob = Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('attempts')->andReturn($attempts);

        return $queueJob;
    }

    /**
     * handle() ganhou esse 3º parâmetro em 64bcf17 (2026-08-08, correção do
     * pedido #189) — refreshBuyerInfo() só importa pro caso de canal
     * externo com dado mascarado, fora do escopo do que estes testes
     * verificam, então um mock genérico "não faz nada" basta.
     */
    private function fakeOrderImportService(): OrderImportService
    {
        $orderImport = Mockery::mock(OrderImportService::class);
        $orderImport->shouldReceive('refreshBuyerInfo')->andReturnNull();

        return $orderImport;
    }

    public function test_handle_logs_success_and_dispatches_email_job_when_invoice_is_authorized(): void
    {
        Queue::fake();
        $order = $this->makeOrder();
        $invoice = Invoice::create(['order_id' => $order->id, 'status' => Invoice::STATUS_AUTHORIZED, 'numero' => 1]);

        $invoices = Mockery::mock(InvoiceService::class);
        $invoices->shouldReceive('issue')->once()->andReturn($invoice);

        (new GenerateInvoiceJob($order->id))->handle($invoices, new OrderFulfillmentTimeline(), $this->fakeOrderImportService());

        $this->assertDatabaseHas('invoice_generation_logs', [
            'order_id' => $order->id,
            'invoice_id' => $invoice->id,
            'status' => InvoiceGenerationLog::STATUS_SUCCESS,
            'error_message' => null,
        ]);
        Queue::assertPushed(SendOrderReceiptEmailJob::class, fn (SendOrderReceiptEmailJob $job) => $job->orderId === $order->id);
    }

    public function test_handle_logs_success_and_does_not_submit_invoice_to_channel_when_invoice_is_external(): void
    {
        Queue::fake();
        $order = $this->makeOrder();
        $order->update(['origin' => Order::ORIGIN_MERCADO_LIVRE, 'external_order_id' => 'ML-1']);
        $invoice = Invoice::create(['order_id' => $order->id, 'status' => Invoice::STATUS_EXTERNAL, 'serie' => 0, 'numero' => $order->id]);

        $invoices = Mockery::mock(InvoiceService::class);
        $invoices->shouldReceive('issue')->once()->andReturn($invoice);

        (new GenerateInvoiceJob($order->id))->handle($invoices, new OrderFulfillmentTimeline(), $this->fakeOrderImportService());

        $this->assertDatabaseHas('invoice_generation_logs', [
            'order_id' => $order->id,
            'invoice_id' => $invoice->id,
            'status' => InvoiceGenerationLog::STATUS_SUCCESS,
            'error_message' => null,
        ]);

        // Canal já emitiu a própria nota — não faz sentido submeter nota
        // nossa (não existe uma de verdade). Confirmação de envio/etiqueta
        // não é mais responsabilidade deste job — dispara direto na
        // importação do pedido (OrderImportService), em paralelo com a nota.
        Queue::assertNotPushed(ConfirmChannelShippingJob::class);
        Queue::assertNotPushed(SubmitInvoiceToChannelJob::class);
        Queue::assertPushed(SendOrderReceiptEmailJob::class);
    }

    public function test_handle_logs_a_clear_failed_message_when_blocked_by_missing_certificate(): void
    {
        Queue::fake();
        $order = $this->makeOrder();
        $invoice = Invoice::create(['order_id' => $order->id, 'status' => Invoice::STATUS_PENDING, 'numero' => 1]);

        $invoices = Mockery::mock(InvoiceService::class);
        $invoices->shouldReceive('issue')->once()->andReturn($invoice);

        (new GenerateInvoiceJob($order->id))->handle($invoices, new OrderFulfillmentTimeline(), $this->fakeOrderImportService());

        $this->assertDatabaseHas('invoice_generation_logs', [
            'order_id' => $order->id,
            'status' => InvoiceGenerationLog::STATUS_FAILED,
            'error_message' => 'Certificado digital não configurado — emissão pendente.',
        ]);
        Queue::assertPushed(SendOrderReceiptEmailJob::class);
    }

    public function test_handle_logs_the_sefaz_rejection_reason_without_retrying(): void
    {
        Queue::fake();
        $order = $this->makeOrder();
        $invoice = Invoice::create([
            'order_id' => $order->id,
            'status' => Invoice::STATUS_REJECTED,
            'numero' => 1,
            'motivo_rejeicao' => '999 - Erro genérico de validação',
        ]);

        $invoices = Mockery::mock(InvoiceService::class);
        $invoices->shouldReceive('issue')->once()->andReturn($invoice);

        (new GenerateInvoiceJob($order->id))->handle($invoices, new OrderFulfillmentTimeline(), $this->fakeOrderImportService());

        $this->assertDatabaseHas('invoice_generation_logs', [
            'order_id' => $order->id,
            'status' => InvoiceGenerationLog::STATUS_FAILED,
            'error_message' => '999 - Erro genérico de validação',
        ]);
        // Rejeição é uma resposta definitiva da SEFAZ — não é reenfileirada
        // pra tentar de novo, mas o e-mail de recibo ainda sai.
        Queue::assertPushed(SendOrderReceiptEmailJob::class);
    }

    public function test_handle_logs_retrying_and_rethrows_while_attempts_remain(): void
    {
        Queue::fake();
        $order = $this->makeOrder();

        $invoices = Mockery::mock(InvoiceService::class);
        $invoices->shouldReceive('issue')->once()->andThrow(new RuntimeException('timeout na comunicação com a SEFAZ'));

        $job = new GenerateInvoiceJob($order->id);
        $job->setJob($this->fakeQueueJobWithAttempts(1));

        $thrown = null;
        try {
            $job->handle($invoices, new OrderFulfillmentTimeline(), $this->fakeOrderImportService());
        } catch (Throwable $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown, 'A exceção técnica precisa propagar pro Laravel decidir o retry.');
        $this->assertDatabaseHas('invoice_generation_logs', [
            'order_id' => $order->id,
            'status' => InvoiceGenerationLog::STATUS_RETRYING,
            'error_message' => 'timeout na comunicação com a SEFAZ',
        ]);
        // Ainda tem tentativas sobrando — o e-mail não deve sair ainda
        // (só sai quando handle() termina sem lançar, ou via failed()).
        Queue::assertNotPushed(SendOrderReceiptEmailJob::class);
    }

    public function test_handle_logs_failed_on_the_last_attempt(): void
    {
        Queue::fake();
        $order = $this->makeOrder();

        $invoices = Mockery::mock(InvoiceService::class);
        $invoices->shouldReceive('issue')->once()->andThrow(new RuntimeException('certificado ilegível'));

        $job = new GenerateInvoiceJob($order->id);
        $job->setJob($this->fakeQueueJobWithAttempts(3)); // == $job->tries

        try {
            $job->handle($invoices, new OrderFulfillmentTimeline(), $this->fakeOrderImportService());
        } catch (Throwable) {
            // esperado — quem decide chamar failed() depois é o worker real, não handle()
        }

        $this->assertDatabaseHas('invoice_generation_logs', [
            'order_id' => $order->id,
            'status' => InvoiceGenerationLog::STATUS_FAILED,
            'error_message' => 'certificado ilegível',
        ]);
    }

    public function test_failed_notifies_admins_and_still_dispatches_the_receipt_email(): void
    {
        Notification::fake();
        Queue::fake();

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $customer = User::factory()->create(['role' => User::ROLE_CUSTOMER]);
        $order = $this->makeOrder();

        (new GenerateInvoiceJob($order->id))->failed(new RuntimeException('certificado expirado'));

        Notification::assertSentTo($admin, InvoiceIssuanceFailedNotification::class);
        Notification::assertNotSentTo($customer, InvoiceIssuanceFailedNotification::class);
        Queue::assertPushed(SendOrderReceiptEmailJob::class, fn (SendOrderReceiptEmailJob $job) => $job->orderId === $order->id);
    }

    public function test_issue_is_idempotent_and_never_creates_a_second_invoice_for_the_same_order(): void
    {
        $order = $this->makeOrder();
        Invoice::create(['order_id' => $order->id, 'status' => Invoice::STATUS_AUTHORIZED, 'numero' => 1]);

        $service = app(InvoiceService::class);
        $result = $service->issue($order->fresh());

        $this->assertSame(1, Invoice::where('order_id', $order->id)->count());
        $this->assertSame(Invoice::STATUS_AUTHORIZED, $result->status);
    }
}
