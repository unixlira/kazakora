<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Services\InvoiceService;
use App\Modules\Marketplace\Jobs\ConfirmChannelShippingJob;
use App\Modules\Marketplace\Jobs\SubmitInvoiceToChannelJob;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Support\OrderImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Destrava sozinho o pedido cuja NOTA não saiu — e, com ela, a etiqueta.
 *
 * Pedido do usuário em 2026-09-07, depois do #1604: "esses problemas devem
 * ser resolvidos automaticamente e depois gerar todas etiquetas". Até aqui
 * o único retry automático de nota era o do próprio job (3 tentativas, ~15
 * min) — passou disso, o pedido ficava parado pra sempre esperando alguém
 * olhar. Foi o que aconteceu com 5 pedidos do Mercado Livre: dois com
 * rejeição 232 (IE do destinatário) de ANTES da correção do XML, dois com
 * 539 (duplicidade) de antes da correção da numeração, e um sem nota
 * nenhuma. Os três problemas já estavam resolvidos no código; ninguém
 * mandou tentar de novo.
 *
 * E destravar a nota basta pra etiqueta sair: nota autorizada dispara
 * SubmitInvoiceToChannelJob, o canal libera o envio, CheckShipmentLabelJob
 * baixa a etiqueta e a impressão automática (PRINT_AUTO_SINCE) manda pra
 * impressora. O botão "Gerar etiquetas em lote" continua sendo a rede pro
 * que ficou pra trás do corte.
 *
 * NÃO mexe em nota DENEGADA (cStat 110/301/302): a SEFAZ queima o número e
 * o problema é cadastral, fora do sistema — retry ali é só barulho.
 */
class RetryStuckInvoices extends Command
{
    protected $signature = 'nfe:retry-stuck
        {--minutos=30 : Só tenta de novo notas paradas há mais que isso (evita martelar a SEFAZ)}
        {--forcar : Ignora a espera acima}
        {--sincrono : Processa na hora, sem passar pela fila}
        {--limite=20 : Teto de notas por rodada — trava de segurança, ver comentário}
        {--canal= : Só este canal (ex: mercado_livre)}';

    protected $description = 'Tenta de novo a NF-e dos pedidos pagos cuja nota ficou pendente, rejeitada ou nunca foi emitida — e redispara o envio travado por causa dela';

    public function handle(): int
    {
        $espera = now()->subMinutes((int) $this->option('minutos'));
        $delegadosAoBling = (array) config('services.bling.invoice_issuer_channels', []);
        $canal = $this->option('canal');

        $this->reconciliarEnviadas($canal, $delegadosAoBling);

        $orders = Order::query()
            ->with('invoice')
            ->where('status', Order::STATUS_PAID)
            ->whereNotIn('origin', array_merge($delegadosAoBling, [Order::ORIGIN_STORE, Order::ORIGIN_MANUAL_INVOICE]))
            ->when($canal, fn ($query) => $query->where('origin', $canal))
            ->where(function ($query) {
                $query->whereDoesntHave('invoice')
                    ->orWhereHas('invoice', fn ($q) => $q->whereIn('status', [Invoice::STATUS_PENDING, Invoice::STATUS_REJECTED]));
            })
            ->orderBy('id')
            ->get()
            // A espera vale por NOTA, não por pedido: pedido sem nota
            // nenhuma nunca tentou, então entra sempre.
            ->filter(fn (Order $order) => $this->option('forcar')
                || $order->invoice === null
                || $order->invoice->updated_at === null
                || $order->invoice->updated_at->lt($espera));

        // TRAVA REAL 2026-09-07, aprendida na primeira execução: rodei isto
        // com BLING_INVOICE_ISSUER_CHANNELS vazio e ele varreu 94 notas do
        // TikTok Shop — canal cuja nota é do BLING, não nossa. Cada
        // rejeição 539 dessas queima um número de NF-e (ver
        // InvoiceService::reserveNewNumber()), e a série 2 pulou de 2041
        // pra 2091 em minutos. Número de nota fiscal não volta.
        //
        // O env foi corrigido (tiktok_shop entrou na lista, que é o certo —
        // ver o incidente da nota dupla de 2026-09-05), mas configuração
        // errada não pode custar 50 números de novo: a partir daqui, um
        // teto por rodada. Se houver mais que isso travado, é acúmulo de
        // verdade e alguém tem que olhar, não é caso pra varredura cega.
        $total = $orders->count();
        $limite = max(1, (int) $this->option('limite'));

        if ($total > $limite) {
            $this->warn("{$total} notas travadas — acima do teto de {$limite} por rodada. Tratando as {$limite} mais antigas; rode de novo (ou aumente --limite) depois de conferir por que são tantas.");
            $orders = $orders->take($limite);
        }

        $this->info("Notas travadas pra tentar de novo: {$orders->count()}");

        foreach ($orders as $order) {
            $motivo = $order->invoice === null
                ? 'nunca emitida'
                : ($order->invoice->status === Invoice::STATUS_PENDING
                    ? 'ficou pendente'
                    : trim(substr((string) $order->invoice->motivo_rejeicao, 0, 90)));

            $this->line("  #{$order->id} ({$order->origin}) — {$motivo}");

            if ($this->option('sincrono')) {
                try {
                    (new GenerateInvoiceJob($order->id))->handle(
                        app(InvoiceService::class),
                        app(OrderFulfillmentTimeline::class),
                        app(OrderImportService::class),
                    );
                    $this->line('     -> '.($order->fresh()->invoice?->status ?? '?'));
                } catch (\Throwable $exception) {
                    $this->warn('     -> falhou: '.substr($exception->getMessage(), 0, 120));
                }

                continue;
            }

            GenerateInvoiceJob::dispatch($order->id);
        }

        // 3ª parte: envio que morreu ESPERANDO a nota ("Etiqueta não ficou
        // disponível após 4h") não volta sozinho depois que ela sai — o
        // canal precisa ser reconsultado. Sem isto, consertar a nota não
        // faria a etiqueta aparecer.
        $enviosTravados = ChannelShipment::query()
            ->where('status', ChannelShipment::STATUS_ERROR)
            ->whereHas('order', fn ($query) => $query
                ->where('status', Order::STATUS_PAID)
                ->when($canal, fn ($q) => $q->where('origin', $canal))
                ->whereHas('invoice', fn ($q) => $q->where('status', Invoice::STATUS_AUTHORIZED)))
            ->get();

        $this->info(PHP_EOL."Envios travados com nota já autorizada: {$enviosTravados->count()}");

        foreach ($enviosTravados as $shipment) {
            $this->line("  pedido #{$shipment->order_id} — reconsultando o canal");
            ConfirmChannelShippingJob::dispatch($shipment->order_id);
        }

        Log::info('nfe.retry_stuck', [
            'notas' => $orders->count(),
            'envios' => $enviosTravados->count(),
        ]);

        return self::SUCCESS;
    }

    /**
     * 1ª parte, e a mais importante: nota que ficou em `sent`.
     *
     * `sent` é o limbo — foi assinada e enviada, mas a resposta da SEFAZ
     * veio sem protocolo (comunicação, timeout, lote em processamento) e
     * NINGUÉM olhava pra ela de novo. O canal não libera envio sem nota
     * autorizada, então a etiqueta nunca sai; e reemitir às cegas arrisca
     * duplicar uma NF-e que talvez já esteja autorizada lá.
     *
     * Consultar por chave é a única resposta confiável, e vem ANTES de
     * qualquer reemissão de propósito: sem isso a rodada seguinte
     * reemitiria em cima de nota autorizada.
     *
     * @param  array<int, string>  $delegadosAoBling
     */
    private function reconciliarEnviadas(?string $canal, array $delegadosAoBling): void
    {
        $enviadas = Invoice::query()
            ->where('status', Invoice::STATUS_SENT)
            ->whereNotNull('chave_acesso')
            ->whereHas('order', fn ($query) => $query
                ->where('status', Order::STATUS_PAID)
                ->whereNotIn('origin', $delegadosAoBling)
                ->when($canal, fn ($q) => $q->where('origin', $canal)))
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limite')))
            ->get();

        $this->info("Notas em limbo (enviadas sem resposta) pra conferir na SEFAZ: {$enviadas->count()}");

        foreach ($enviadas as $invoice) {
            try {
                $resolvida = app(InvoiceService::class)->reconcileSent($invoice);
                $this->line("  #{$invoice->order_id} nº {$invoice->numero} -> {$resolvida->status}".
                    ($resolvida->motivo_rejeicao ? ' ('.substr($resolvida->motivo_rejeicao, 0, 70).')' : ''));

                // Autorizada agora: manda pro canal, que é o que destrava a
                // etiqueta. O fluxo normal faz isso no GenerateInvoiceJob.
                if ($resolvida->status === Invoice::STATUS_AUTHORIZED) {
                    SubmitInvoiceToChannelJob::dispatch($resolvida->order_id);
                }
            } catch (\Throwable $exception) {
                $this->warn("  #{$invoice->order_id} -> falhou na consulta: ".substr($exception->getMessage(), 0, 110));
            }
        }

        $this->newLine();
    }
}
