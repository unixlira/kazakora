<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Jobs\ConfirmChannelShippingJob;
use App\Modules\Marketplace\Models\ChannelInvoiceSubmission;
use RuntimeException;
use Throwable;

/**
 * Envia a NF-e autorizada de um pedido de canal externo pro canal
 * correspondente. Canal-agnóstico — delega o formato/endpoint real pro
 * driver (MarketplaceChannelDriver::submitInvoice()).
 *
 * Pipeline de nota fiscal, independente do pipeline de envio/etiqueta (que
 * dispara direto na importação do pedido — ver OrderImportService): sucesso
 * ou falha aqui não confirma nem bloqueia o frete, só afeta a nota em si.
 *
 * Achado real 2026-08-08 (pedido #188): na Shopee, ConfirmChannelShippingJob
 * costuma rodar ANTES da nota terminar de processar e "termina" (mesmo sem
 * ship_order ter sido aceito de verdade — ver
 * ShopeeDriver::confirmShipping()) sem re-tentar sozinho depois que a nota
 * finalmente sai. Reprocessar a nota fiscal manualmente (fiscal preenchido
 * tarde, ex: produto auto-importado — ver ProductFiscalController) não
 * adiantava nada sozinho: precisa ALGUÉM re-chamar a confirmação de envio
 * depois. Disparar aqui, assim que o canal aceita a nota de verdade, fecha
 * o loop inteiro sem precisar de intervenção manual — idempotente pros
 * canais que já confirmaram o envio antes (ML/Amazon, cujo confirmShipping
 * só CONSULTA a decisão já tomada, nunca refaz nada).
 */
class ChannelInvoiceSubmissionService
{
    public function __construct(
        private readonly MarketplaceDriverManager $manager,
        private readonly OrderFulfillmentTimeline $timeline,
    ) {
    }

    public function submit(Order $order): ChannelInvoiceSubmission
    {
        $order->loadMissing('invoice');
        $invoice = $order->invoice;

        $submission = ChannelInvoiceSubmission::query()->firstOrCreate(
            ['order_id' => $order->id, 'invoice_id' => $invoice->id, 'channel' => $order->origin],
            ['status' => ChannelInvoiceSubmission::STATUS_PENDING],
        );

        if ($submission->status === ChannelInvoiceSubmission::STATUS_ACCEPTED) {
            return $submission;
        }

        try {
            $result = $this->manager->driver($order->origin)->submitInvoice($order, $invoice);
        } catch (Throwable $exception) {
            $submission->update([
                'status' => ChannelInvoiceSubmission::STATUS_ERROR,
                'error_message' => $exception->getMessage(),
                'submitted_at' => now(),
            ]);

            $this->timeline->record($order, OrderFulfillmentEvent::STEP_INVOICE_SUBMITTED, OrderFulfillmentEvent::STATUS_FAILED, $exception->getMessage());

            throw $exception;
        }

        $sent = $result['status'] === 'sent';
        $errorMessage = $result['response']['error'] ?? 'Canal rejeitou o envio da nota.';

        $submission->update([
            'status' => $sent ? ChannelInvoiceSubmission::STATUS_SENT : ChannelInvoiceSubmission::STATUS_ERROR,
            'external_reference' => $result['external_reference'],
            'response_payload' => $result['response'],
            'error_message' => $sent ? null : $errorMessage,
            'submitted_at' => now(),
            'responded_at' => now(),
        ]);

        $this->timeline->record(
            $order,
            OrderFulfillmentEvent::STEP_INVOICE_SUBMITTED,
            $sent ? OrderFulfillmentEvent::STATUS_SUCCESS : OrderFulfillmentEvent::STATUS_FAILED,
            $sent ? 'Nota enviada ao canal' : $errorMessage,
        );

        if ($sent) {
            ConfirmChannelShippingJob::dispatch($order->id)->afterCommit();

            return $submission;
        }

        // BUG REAL 2026-08-08 (pedido #203, venda que nunca imprimiu):
        // driver()->submitInvoice() nunca lança exceção pra um erro do
        // CANAL (só pra falha técnica de rede/certificado — ver
        // ShopeeDriver::submitInvoice(), que captura ShopeeException e
        // devolve status=error em vez de deixar subir) — de propósito,
        // pra poder registrar a resposta real do canal aqui. Mas isso
        // significava que SubmitInvoiceToChannelJob::handle() NUNCA via
        // uma exceção, então terminava "com sucesso" do ponto de vista do
        // Laravel mesmo quando a Shopee rejeitou — o retry/backoff do job
        // nunca chegava a rodar uma segunda vez. Confirmado ao vivo: a
        // Shopee rejeitou "This order cannot accept invoices yet" no
        // primeiro envio (segundos depois do pedido pago) e aceitou de
        // bandeja chamando o EXATO MESMO código ~50min depois — um atraso
        // de propagação interna do lado da Shopee, não um erro
        // permanente. Sem uma segunda tentativa de verdade, o pedido
        // ficava preso pra sempre em "nota fiscal não enviada" (e por
        // consequência sem confirmar envio, sem etiqueta, sem impressão)
        // até alguém notar e reenviar manualmente. Lança aqui pra
        // finalmente acionar o retry/backoff real do job.
        throw new RuntimeException($errorMessage);
    }
}
