<?php

namespace App\Modules\Marketplace\Jobs;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Support\ChannelInvoiceSubmissionService;
use App\Notifications\InvoiceIssuanceFailedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Throwable;

/**
 * BUG REAL 2026-09-29: log de produção com "Shipment 48108258060's status is
 * wrong" a cada ~2s. O KoraSync consulta etiqueta-status a cada 2s por até
 * 3 min, e cada consulta (DashboardAgentController::labelFiscalBlock())
 * enfileirava um job NOVO deste — sem trava, cada um com 6 tentativas,
 * dezenas em paralelo mandando a mesma nota pro canal. Agora: um job por
 * pedido (ShouldBeUnique, mesmo padrão do GenerateInvoiceJob), a consulta
 * não enfileira mais nada, e recusa permanente não gasta retry.
 */
class SubmitInvoiceToChannelJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Achado real 2026-08-08 (pedido #203, venda que nunca imprimiu): a
    // Shopee às vezes rejeita upload_invoice_doc por alguns minutos depois
    // do pedido pago ("This order cannot accept invoices yet" — atraso de
    // propagação interna do lado deles, confirmado ao vivo: a mesma
    // chamada, sem nenhuma mudança, aceitou de bandeja ~50min depois). 3
    // tentativas (~21min) não é folga suficiente pra isso — mesma lição já
    // aplicada em ConfirmChannelShippingJob, mesmo backoff, pelo mesmo
    // motivo: precisa sobreviver ao pipeline inteiro antes de desistir.
    public int $tries = 6;

    public array $backoff = [60, 300, 900, 1800, 3600, 7200];

    public function __construct(public readonly int $orderId)
    {
        // Mesma fila isolada da nota fiscal — ver GenerateInvoiceJob.
        $this->onQueue('nfe');
    }

    public function uniqueId(): string
    {
        return (string) $this->orderId;
    }

    /**
     * Cobre o backoff inteiro (~3h50) — a trava some sozinha se o worker
     * morrer no meio sem liberar.
     */
    public function uniqueFor(): int
    {
        return 14400;
    }

    public function handle(ChannelInvoiceSubmissionService $service): void
    {
        $order = Order::with('channelShipment')->findOrFail($this->orderId);
        $shipment = $order->channelShipment;

        // Venda cancelada no canal: mandar a nota pra ela não serve pra nada
        // (o canal recusa) e só gera erro. A NF-e em si é tratada à parte
        // (aviso de cancelamento em OrderImportService::syncStatus()).
        if ($order->status === Order::STATUS_CANCELLED) {
            Log::info('marketplace.invoice_submission.skipped_cancelled_order', ['order_id' => $order->id]);

            return;
        }

        // Mercado Livre xd_drop_off agendado pode autorizar a NF-e local agora,
        // mas a API rejeita o XML com "Shipment status is wrong" até perto
        // da data de liberação do envio. Não gasta retries nem gera alerta
        // falso: solta o job para a mesma janela em que a etiqueta será
        // liberada, igual ao CheckShipmentLabelJob.
        if ($order->origin === Order::ORIGIN_MERCADO_LIVRE && $shipment?->scheduled_for) {
            $releaseAt = \Illuminate\Support\Carbon::parse($shipment->scheduled_for);

            if ($releaseAt->isFuture()) {
                $this->release(max(60, $releaseAt->getTimestamp() - now()->getTimestamp()));

                return;
            }
        }

        try {
            $service->submit($order);
        } catch (RuntimeException $exception) {
            // "Shipment X's status is wrong" (Mercado Livre) é transitório
            // enquanto o pedido está pago (envio agendado ainda não
            // liberado — ver acima), mas PERMANENTE quando o pedido já saiu
            // de "pago": o envio andou (enviado/entregue) e o canal nunca
            // mais aceita nota nele. Falha de vez em vez de repetir 6x.
            if ($order->status !== Order::STATUS_PAID
                && str_contains(mb_strtolower($exception->getMessage()), 'status is wrong')) {
                Log::warning('marketplace.invoice_submission.permanent_channel_error', [
                    'order_id' => $order->id,
                    'order_status' => $order->status,
                    'error' => $exception->getMessage(),
                ]);

                $this->fail($exception);

                return;
            }

            throw $exception;
        }
    }

    /**
     * Antes desse método existir, esgotar as ~3h de retry sem sucesso
     * ficava só registrado em failed_jobs, sem avisar ninguém — mesmo
     * buraco de visibilidade já fechado em ConfirmChannelShippingJob e nos
     * jobs de importação de webhook (ver WebhookImportFailedNotification).
     */
    public function failed(?Throwable $exception): void
    {
        $order = Order::find($this->orderId);

        if (! $order) {
            return;
        }

        $admins = User::query()->where('role', User::ROLE_ADMIN)->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new InvoiceIssuanceFailedNotification($order, $exception?->getMessage() ?? 'Erro desconhecido ao enviar a nota fiscal pro canal.'));
        }
    }
}
