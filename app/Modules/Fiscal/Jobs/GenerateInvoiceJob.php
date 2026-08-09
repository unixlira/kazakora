<?php

namespace App\Modules\Fiscal\Jobs;

use App\Models\User;
use App\Modules\Checkout\Jobs\SendOrderReceiptEmailJob;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Models\InvoiceGenerationLog;
use App\Modules\Fiscal\Services\InvoiceService;
use App\Modules\Marketplace\Jobs\SubmitInvoiceToChannelJob;
use App\Modules\Marketplace\Support\OrderImportService;
use App\Notifications\InvoiceIssuanceFailedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use NFePHP\Common\Exception\ValidatorException;
use Throwable;

/**
 * Emite a NF-e de um pedido pago, de forma assíncrona (disparado pelo
 * webhook do Stripe/endpoint de status, nunca chamado sincronamente).
 *
 * Retry só se aplica a falha TÉCNICA (conexão, SOAP, certificado, resposta
 * ilegível) — ver InvoiceService::issue(). Uma resposta definitiva da SEFAZ
 * (autorizada/rejeitada/denegada) ou a ausência de certificado configurado
 * não geram exceção, então terminam o job normalmente (sem retry) e já
 * disparam o e-mail de recibo em seguida. Falha de validação local do XML
 * (ValidatorException, antes de qualquer chamada à SEFAZ) também é
 * terminal na primeira tentativa — é sempre um erro determinístico de
 * dados, nunca resolvido por retry.
 */
class GenerateInvoiceJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $orderId)
    {
        // Fila própria (não 'default') — isola a nota fiscal do resto
        // (envio, e-mail, sincronização de estoque), como pedido
        // explicitamente: a nota pode ficar lenta/travada (SEFAZ fora do
        // ar, certificado ruim) sem atrasar nada mais na fila. Precisa do
        // worker do homolog escutando essa fila também
        // (`queue:work --queue=default,nfe`), não só a default — ver
        // comando no cron do Hostinger. Setado via onQueue() (não uma
        // redeclaração de $queue) porque o trait Queueable já declara essa
        // propriedade — redeclarar com valor default diferente é rejeitado
        // pelo PHP como composição incompatível.
        $this->onQueue('nfe');
    }

    public function uniqueId(): string
    {
        return (string) $this->orderId;
    }

    public function uniqueFor(): int
    {
        return 3600;
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(InvoiceService $invoices, OrderFulfillmentTimeline $timeline, OrderImportService $orderImport): void
    {
        $order = Order::findOrFail($this->orderId);

        // Achado real 2026-08-08 (pedido #189) — ver comentário completo em
        // OrderImportService::refreshBuyerInfo(): a Shopee mascara nome e
        // omite CPF do comprador até o pedido avançar de status, então o
        // dado gravado na importação pode estar incompleto mesmo quando o
        // canal já tem o dado real disponível agora. Tenta buscar de novo
        // ANTES de montar o XML, em vez de só falhar 3 vezes com o mesmo
        // erro ("não foi possível identificar o CPF/CNPJ do comprador")
        // esperando um webhook futuro consertar sozinho.
        $orderImport->refreshBuyerInfo($order);
        $order->refresh();

        try {
            $invoice = $invoices->issue($order);

            $isTerminalSuccess = in_array($invoice->status, [Invoice::STATUS_AUTHORIZED, Invoice::STATUS_EXTERNAL], true);

            $errorMessage = match (true) {
                $invoice->status === Invoice::STATUS_AUTHORIZED => null,
                $invoice->status === Invoice::STATUS_EXTERNAL => null,
                $invoice->status === Invoice::STATUS_PENDING => 'Certificado digital não configurado — emissão pendente.',
                default => $invoice->motivo_rejeicao,
            };

            InvoiceGenerationLog::create([
                'order_id' => $order->id,
                'invoice_id' => $invoice->id,
                'attempt' => $this->attempts(),
                'status' => $isTerminalSuccess
                    ? InvoiceGenerationLog::STATUS_SUCCESS
                    : InvoiceGenerationLog::STATUS_FAILED,
                'error_message' => $errorMessage,
            ]);

            $timeline->record(
                $order,
                OrderFulfillmentEvent::STEP_INVOICE_ISSUED,
                $isTerminalSuccess ? OrderFulfillmentEvent::STATUS_SUCCESS : OrderFulfillmentEvent::STATUS_FAILED,
                match (true) {
                    $invoice->status === Invoice::STATUS_AUTHORIZED => "NF-e autorizada, chave {$invoice->chave_acesso}",
                    $invoice->status === Invoice::STATUS_EXTERNAL => 'Nota fiscal emitida pelo próprio canal — Kazakora não emite pra evitar duplicidade.',
                    default => $errorMessage,
                },
            );

            // Nota nossa autorizada: envia pro canal via API (etapa própria
            // do pipeline de nota fiscal, não afeta envio/etiqueta — esses
            // já disparam direto na importação do pedido, ver
            // OrderImportService). Pedido do site (origin=loja) não passa
            // por canal nenhum, não tem o que enviar aqui — e pedido de
            // emissão manual avulsa (origin=nota_fiscal_avulsa, 2026-08-09)
            // também não: sem isso, o job tentava resolver um driver de
            // marketplace pra um "canal" que não existe, falhava 6 vezes em
            // ~3h e disparava um alerta de erro pros admins do nada.
            if ($invoice->status === Invoice::STATUS_AUTHORIZED
                && ! in_array($order->origin, [Order::ORIGIN_STORE, Order::ORIGIN_MANUAL_INVOICE], true)) {
                SubmitInvoiceToChannelJob::dispatch($order->id)->afterCommit();
            }
        } catch (ValidatorException $exception) {
            // XML inválido localmente (barrado pelo validador do sped-nfe
            // antes de qualquer chamada à SEFAZ) é sempre um erro
            // determinístico dos dados/geração do XML — tentar de novo com
            // os mesmos dados nunca vai dar certo. Falha na hora em vez de
            // gastar minutos em retries com backoff pra chegar no mesmo
            // erro (foi o que aconteceu de verdade nos pedidos #15/#16,
            // 2026-08-03: ~7min de retry até desistir).
            InvoiceGenerationLog::create([
                'order_id' => $order->id,
                'invoice_id' => $order->fresh()->invoice?->id,
                'attempt' => $this->attempts(),
                'status' => InvoiceGenerationLog::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
            ]);

            $timeline->record($order, OrderFulfillmentEvent::STEP_INVOICE_ISSUED, OrderFulfillmentEvent::STATUS_FAILED, $exception->getMessage());

            $this->fail($exception);

            return;
        } catch (Throwable $exception) {
            InvoiceGenerationLog::create([
                'order_id' => $order->id,
                'invoice_id' => $order->fresh()->invoice?->id,
                'attempt' => $this->attempts(),
                'status' => $this->attempts() < $this->tries
                    ? InvoiceGenerationLog::STATUS_RETRYING
                    : InvoiceGenerationLog::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
            ]);

            if ($this->attempts() >= $this->tries) {
                $timeline->record($order, OrderFulfillmentEvent::STEP_INVOICE_ISSUED, OrderFulfillmentEvent::STATUS_FAILED, $exception->getMessage());
            }

            throw $exception;
        }

        SendOrderReceiptEmailJob::dispatch($order->id);
    }

    /**
     * Chamado pelo Laravel quando as $tries se esgotam de verdade (falha
     * técnica persistente). O e-mail de recibo sai mesmo assim (sem anexo),
     * e os admins são avisados pra revisar o pedido manualmente.
     */
    public function failed(?Throwable $exception): void
    {
        $order = Order::find($this->orderId);

        if (! $order) {
            return;
        }

        $admins = User::query()->where('role', User::ROLE_ADMIN)->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new InvoiceIssuanceFailedNotification($order, $exception?->getMessage() ?? 'Erro desconhecido'));
        }

        SendOrderReceiptEmailJob::dispatch($order->id);
    }
}
