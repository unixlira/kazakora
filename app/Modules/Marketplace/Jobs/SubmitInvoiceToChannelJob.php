<?php

namespace App\Modules\Marketplace\Jobs;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Support\ChannelInvoiceSubmissionService;
use App\Notifications\InvoiceIssuanceFailedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use Throwable;

class SubmitInvoiceToChannelJob implements ShouldQueue
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

    public function handle(ChannelInvoiceSubmissionService $service): void
    {
        $order = Order::findOrFail($this->orderId);

        $service->submit($order);
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
