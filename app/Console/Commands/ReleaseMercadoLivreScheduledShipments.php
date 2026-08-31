<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Jobs\CheckShipmentLabelJob;
use App\Modules\Marketplace\Jobs\ConfirmChannelShippingJob;
use App\Modules\Marketplace\Jobs\SubmitInvoiceToChannelJob;
use App\Modules\Marketplace\Models\ChannelInvoiceSubmission;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Liberação operacional matinal das vendas Mercado Livre agendadas.
 *
 * O fluxo normal já armazena ChannelShipment.scheduled_for quando a venda é
 * importada/confirmada, mas venda de Coleta/Places pode ficar dias escondida
 * do KoraSync porque a etiqueta só aparece no dia prometido pelo ML. Este
 * comando é a rede de segurança das 7h (+ 00:05/06:05, ver routes/console.php):
 * reimporta pedidos recentes do ML, pega envios agendados vencidos/para hoje,
 * garante que NF-e e envio ao canal sejam reprocessados quando ainda
 * estiverem pendentes e cutuca a etiqueta. Tudo é idempotente: GenerateInvoiceJob
 * é único por pedido, submitInvoice() não reenvia quando já está SENT/ACCEPTED,
 * CheckShipmentLabelJob é único por shipment, e LabelFetchService cria
 * PrintJob com firstOrCreate(order_id).
 *
 * RECRIADO 2026-08-31 (achado ao vivo: o arquivo original tinha sumido do
 * servidor sem nunca ter sido commitado — um deploy de rotina, sem relação
 * nenhuma com este comando, resetou o código pro estado do git via rsync
 * --delete, e como isso nunca tinha ido pro git, foi embora junto. 27
 * pacotes agendados pra entregar sem ninguém verificando a etiqueta
 * automaticamente). Desta vez fica commitado de propósito.
 *
 * Achado no mesmo dia: envio agendado (Coleta/Places) do Mercado Livre
 * rejeita a NF-e própria com "Access denied, you must use the biller of
 * MercadoLibre" — o CANAL usa a nota fiscal DELE pra esse tipo específico
 * de logística, não a nossa (confirmado comparando com pedidos ML normais
 * do mesmo dia, que enviaram nota sem problema). Isso é esperado, não é
 * falha — mantido aqui do mesmo jeito (idempotente, não trava nada) porque
 * não afeta a etiqueta, que é liberada pelo canal independente da nossa
 * nota ter sido aceita ou não.
 */
class ReleaseMercadoLivreScheduledShipments extends Command
{
    protected $signature = 'marketplace:release-scheduled-mercadolivre
        {--sync : Sincroniza pedidos recentes do Mercado Livre antes de liberar os agendados}
        {--desde= : Data inicial da sincronização ML (Y-m-d), padrão: 14 dias atrás}
        {--ate= : Data final da sincronização ML (Y-m-d), padrão: hoje}
        {--dry-run : Só mostra o que faria, sem enfileirar jobs nem sincronizar o canal}';

    protected $description = 'Libera vendas agendadas do Mercado Livre no dia: NF-e pendente, envio da nota ao canal, etiqueta/print job e KoraSync';

    public function handle(): int
    {
        if ($this->option('sync') && ! $this->option('dry-run')) {
            $this->syncMercadoLivreOrders();
        } elseif ($this->option('sync') && $this->option('dry-run')) {
            $this->line('dry-run: sincronização ML pulada.');
        }

        $tomorrow = now()->startOfDay()->addDay();

        $shipments = ChannelShipment::query()
            ->where('channel', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<', $tomorrow)
            ->whereNotIn('status', [ChannelShipment::STATUS_LABEL_READY, ChannelShipment::STATUS_LABEL_DOWNLOADED])
            ->whereHas('order', fn ($query) => $query->where('status', Order::STATUS_PAID))
            ->with(['order.invoice'])
            ->orderBy('scheduled_for')
            ->get();

        if ($shipments->isEmpty()) {
            $this->info('Nenhuma venda agendada do Mercado Livre vencida/para hoje aguardando liberação.');

            return self::SUCCESS;
        }

        $stats = [
            'shipments' => $shipments->count(),
            'invoice_jobs' => 0,
            'submission_jobs' => 0,
            'shipping_jobs' => 0,
            'label_jobs' => 0,
        ];

        foreach ($shipments as $shipment) {
            $order = $shipment->order;

            if (! $order) {
                continue;
            }

            $actions = [];

            if ($this->shouldGenerateInvoice($order)) {
                $stats['invoice_jobs']++;
                $actions[] = 'NF-e';

                if (! $this->option('dry-run')) {
                    GenerateInvoiceJob::dispatch($order->id);
                }
            }

            if ($this->shouldSubmitInvoiceToChannel($order)) {
                $stats['submission_jobs']++;
                $actions[] = 'envio NF-e ao ML';

                if (! $this->option('dry-run')) {
                    SubmitInvoiceToChannelJob::dispatch($order->id);
                }
            }

            if (in_array($shipment->status, [ChannelShipment::STATUS_PENDING, ChannelShipment::STATUS_ERROR], true)) {
                $stats['shipping_jobs']++;
                $actions[] = 'confirmação de envio';

                if (! $this->option('dry-run')) {
                    ConfirmChannelShippingJob::dispatch($order->id);
                }
            }

            $stats['label_jobs']++;
            $actions[] = 'checagem de etiqueta/impressão';

            if (! $this->option('dry-run')) {
                CheckShipmentLabelJob::dispatch(
                    $shipment->id,
                    CarbonImmutable::now()->addHours(24),
                );
            }

            $this->line(sprintf(
                'Pedido #%d (%s, agendado %s): %s.',
                $order->id,
                $order->external_order_id ?: 'sem ID externo',
                $shipment->scheduled_for->format('d/m/Y H:i'),
                implode(', ', $actions),
            ));
        }

        Log::info('marketplace.release_scheduled_mercadolivre.finished', $stats);

        $this->info(sprintf(
            'Liberação ML agendada: %d envio(s), %d NF-e, %d envio(s) de NF-e ao ML, %d confirmação(ões) de envio, %d checagem(ns) de etiqueta.',
            $stats['shipments'],
            $stats['invoice_jobs'],
            $stats['submission_jobs'],
            $stats['shipping_jobs'],
            $stats['label_jobs'],
        ));

        return self::SUCCESS;
    }

    private function syncMercadoLivreOrders(): void
    {
        $from = $this->option('desde')
            ? Carbon::parse($this->option('desde'))->toDateString()
            : now()->subDays(14)->toDateString();

        $to = $this->option('ate')
            ? Carbon::parse($this->option('ate'))->toDateString()
            : now()->toDateString();

        $this->line("Sincronizando Mercado Livre de {$from} até {$to} antes da liberação...");

        $exitCode = Artisan::call('orders:sync-mercadolivre', [
            '--desde' => $from,
            '--ate' => $to,
        ]);

        $output = trim(Artisan::output());

        if ($output !== '') {
            $this->line($output);
        }

        Log::info('marketplace.release_scheduled_mercadolivre.sync_finished', [
            'from' => $from,
            'to' => $to,
            'exit_code' => $exitCode,
        ]);
    }

    private function shouldGenerateInvoice(Order $order): bool
    {
        $invoice = $order->invoice;

        if (! $invoice) {
            return true;
        }

        return in_array($invoice->status, [
            Invoice::STATUS_PENDING,
            Invoice::STATUS_SIGNED,
            Invoice::STATUS_SENT,
            Invoice::STATUS_REJECTED,
            Invoice::STATUS_ERROR,
            Invoice::STATUS_EXTERNAL,
        ], true);
    }

    private function shouldSubmitInvoiceToChannel(Order $order): bool
    {
        $invoice = $order->invoice;

        if (! $invoice || $invoice->status !== Invoice::STATUS_AUTHORIZED) {
            return false;
        }

        return ! ChannelInvoiceSubmission::query()
            ->where('order_id', $order->id)
            ->where('channel', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)
            ->whereIn('status', [ChannelInvoiceSubmission::STATUS_SENT, ChannelInvoiceSubmission::STATUS_ACCEPTED])
            ->exists();
    }
}
