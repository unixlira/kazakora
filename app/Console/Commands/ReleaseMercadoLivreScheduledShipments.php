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
 * Liberação operacional das vendas Mercado Livre agendadas.
 *
 * O fluxo normal já armazena ChannelShipment.scheduled_for quando a venda é
 * importada/confirmada, mas venda de Coleta/Places pode ficar dias escondida
 * do KoraSync porque a etiqueta só aparece no dia prometido pelo ML. Este
 * comando é a rede de segurança que roda repetidamente a partir das 6h (ver
 * routes/console.php): reimporta pedidos recentes do ML (só na 1ª passada
 * do dia, --sync), pega envios agendados vencidos/para hoje e roda o
 * pipeline completo (NF-e pendente, envio da nota ao canal, confirmação de
 * envio, etiqueta), e ainda reconfere os agendados PRÓXIMOS (não vencidos
 * ainda) só pra manter a data em dia. Tudo é idempotente: GenerateInvoiceJob
 * é único por pedido, submitInvoice() não reenvia quando já está SENT/ACCEPTED,
 * CheckShipmentLabelJob é único por shipment, ConfirmChannelShippingJob pode
 * rodar em cima de um envio já confirmado sem duplicar nada, e
 * LabelFetchService cria PrintJob com firstOrCreate(order_id).
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
 *
 * BUG REAL 2026-08-31 (relatado pelo usuário: pedido "aberto" no KoraSync
 * com a data de entrega errada, sem nunca ser liberado): scheduled_for é
 * gravado 1x, na hora que ChannelShippingService::confirm() roda pela
 * primeira vez — daí em diante SÓ era reconsultado no canal quando o
 * status virava pending/error (ou seja, depois de CheckShipmentLabelJob já
 * ter esgotado as 24h pós-prazo sem sucesso, no mínimo 1 dia de atraso pra
 * perceber). Se o Mercado Livre muda a data prometida DEPOIS da nossa 1ª
 * confirmação — pra antes OU pra depois do que guardamos — a query abaixo
 * só olha quem já está "vencido/pra hoje" pela NOSSA data salva: se ela
 * ficou desatualizada apontando muito pro futuro, o envio nunca entra
 * nessa lista pra ser reconferido, e fica invisível indefinidamente mesmo
 * que o canal já tenha liberado ou reagendado antes. Correção: reconfere
 * TODO envio agendado ainda sem etiqueta dentro de um horizonte próximo
 * (não só o vencido/pra hoje), sempre batendo no canal de novo — não só
 * relendo o scheduled_for já salvo.
 */
class ReleaseMercadoLivreScheduledShipments extends Command
{
    /**
     * Janela de "agendado próximo" que ainda entra na reconferência leve
     * (só refresca a data/status no canal, sem disparar NF-e/etiqueta) —
     * cobre com folga o prazo mais comum de agendamento do ML (dias, não
     * semanas) sem ficar reconferindo pedido agendado pra daqui 2 meses
     * toda passada.
     */
    private const UPCOMING_HORIZON_DAYS = 14;

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
        $horizon = now()->startOfDay()->addDays(self::UPCOMING_HORIZON_DAYS);

        $baseQuery = fn () => ChannelShipment::query()
            ->where('channel', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)
            ->whereNotNull('scheduled_for')
            ->whereNotIn('status', [ChannelShipment::STATUS_LABEL_READY, ChannelShipment::STATUS_LABEL_DOWNLOADED])
            ->whereHas('order', fn ($query) => $query->where('status', Order::STATUS_PAID));

        $dueShipments = $baseQuery()
            ->where('scheduled_for', '<', $tomorrow)
            ->with(['order.invoice'])
            ->orderBy('scheduled_for')
            ->get();

        // Mesma regra da query principal, só que pro que ainda NÃO venceu —
        // reconfirma via API pra pegar de cara qualquer correção de data
        // que o canal fizer, em vez de só descobrir isso quando o prazo já
        // salvo (que pode estar errado) chegar.
        $upcomingShipments = $baseQuery()
            ->whereBetween('scheduled_for', [$tomorrow, $horizon])
            ->orderBy('scheduled_for')
            ->get();

        if ($dueShipments->isEmpty() && $upcomingShipments->isEmpty()) {
            $this->info('Nenhuma venda agendada do Mercado Livre pendente de liberação ou reconferência.');

            return self::SUCCESS;
        }

        $stats = [
            'shipments' => $dueShipments->count(),
            'invoice_jobs' => 0,
            'submission_jobs' => 0,
            'shipping_jobs' => 0,
            'label_jobs' => 0,
            'upcoming_refreshed' => 0,
        ];

        foreach ($dueShipments as $shipment) {
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

            // Sempre reconfirma no canal aqui, não só quando status é
            // pending/error (ver BUG REAL 2026-08-31 no docblock da classe)
            // — confirm() é seguro rodar de novo em cima de um envio já
            // CONFIRMED, só atualiza os mesmos campos (inclusive
            // scheduled_for, se o canal corrigiu) e redispara o
            // CheckShipmentLabelJob com prazo renovado.
            $stats['shipping_jobs']++;
            $actions[] = 'reconfirmação de envio (canal)';

            if (! $this->option('dry-run')) {
                ConfirmChannelShippingJob::dispatch($order->id);
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

        foreach ($upcomingShipments as $shipment) {
            if (! $shipment->order) {
                continue;
            }

            $stats['upcoming_refreshed']++;

            if (! $this->option('dry-run')) {
                ConfirmChannelShippingJob::dispatch($shipment->order_id);
            }
        }

        if ($upcomingShipments->isNotEmpty()) {
            $this->line(sprintf(
                '%d venda(s) agendada(s) pra depois de amanhã reconferida(s) no canal (só data/status, sem NF-e/etiqueta ainda).',
                $stats['upcoming_refreshed'],
            ));
        }

        Log::info('marketplace.release_scheduled_mercadolivre.finished', $stats);

        $this->info(sprintf(
            'Liberação ML agendada: %d envio(s) vencido(s), %d NF-e, %d envio(s) de NF-e ao ML, %d reconfirmação(ões) de envio, %d checagem(ns) de etiqueta, %d agendado(s) futuro(s) reconferido(s).',
            $stats['shipments'],
            $stats['invoice_jobs'],
            $stats['submission_jobs'],
            $stats['shipping_jobs'],
            $stats['label_jobs'],
            $stats['upcoming_refreshed'],
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
