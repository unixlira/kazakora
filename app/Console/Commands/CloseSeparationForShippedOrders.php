<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Marketplace\Support\OrderImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dá baixa na separação de pedido que o canal já considera despachado.
 *
 * Pedido explícito do usuário em 2026-09-05: a fila do KoraSync tinha 211
 * cards, o mais antigo de 06/08, entupida desde o problema das etiquetas.
 * A fila é `status = paid` AND `packed_at IS NULL` (ver
 * DashboardAgentController::queue), então pedido que saiu de verdade mas
 * nunca teve o clique de separar fica lá para sempre — ninguém vai clicar
 * em "separar" num pedido que já foi embora.
 *
 * Duas passagens:
 *
 * 1. Pedido JÁ marcado como shipped/completed que ficou sem `packed_at`.
 *    Some da fila sozinho (a query exige paid), mas o histórico fica
 *    mentindo que nunca foi separado — e é o que alimenta relatório.
 * 2. Pedido ainda `paid`: reconsulta o canal (mesma engrenagem que
 *    o antigo porteiro da separação usava) e, se voltar despachado, fecha.
 *
 * NUNCA inventa despacho: só fecha o que o canal afirma. Se o canal não
 * informa envio, o pedido continua na fila — é o caso do TikTok Shop até
 * BLING_SITUACOES_ENVIADO ser preenchido (ver TikTokShopDriver).
 */
class CloseSeparationForShippedOrders extends Command
{
    protected $signature = 'separation:close-shipped
        {--dias=45 : Janela de pedidos a reconferir}
        {--sem-reconsulta : Não bate no canal; só fecha quem já está shipped/completed aqui}
        {--dry-run : Mostra o que faria, sem gravar nada}';

    protected $description = 'Fecha a separação de pedidos que o canal já considera enviados';

    public function handle(OrderImportService $importer, OrderFulfillmentTimeline $timeline): int
    {
        $seco = (bool) $this->option('dry-run');
        $desde = now()->subDays((int) $this->option('dias'));
        $fechados = 0;

        if ($seco) {
            $this->warn('DRY-RUN: nada será gravado.');
        }

        // Passagem 1 — já despachado aqui, mas sem baixa de separação.
        $jaEnviados = Order::query()
            ->nonPurchaseReturn()
            ->whereIn('status', [Order::STATUS_SHIPPED, Order::STATUS_COMPLETED])
            ->whereNull('packed_at')
            ->where('created_at', '>=', $desde)
            ->get();

        $this->info($jaEnviados->count().' pedido(s) já enviados aqui e sem baixa de separação.');

        foreach ($jaEnviados as $order) {
            $fechados += $this->fechar($order, $timeline, $seco, "{$order->origin} já constava como {$order->status}");
        }

        if ($this->option('sem-reconsulta')) {
            $this->info("Concluído: {$fechados} baixa(s).");

            return self::SUCCESS;
        }

        // Passagem 2 — ainda na fila; pergunta ao canal.
        $naFila = Order::query()
            ->nonPurchaseReturn()
            ->where('status', Order::STATUS_PAID)
            ->whereNull('packed_at')
            ->whereNotNull('external_order_id')
            ->where('created_at', '>=', $desde)
            ->get();

        $this->info($naFila->count().' pedido(s) na fila para reconsultar no canal.');
        $barra = $this->output->createProgressBar($naFila->count());

        foreach ($naFila as $order) {
            $barra->advance();

            try {
                // Mesma chamada do clique de separar: import() é o único
                // caminho que atualiza orders.status (via syncStatus).
                $importer->import($order->origin, (string) $order->external_order_id);
            } catch (Throwable $exception) {
                // Falha-aberto, como o antigo porteiro da separação: canal fora
                // do ar não pode derrubar a varredura inteira.
                Log::warning('separation.close_shipped.channel_check_failed', [
                    'order_id' => $order->id,
                    'channel' => $order->origin,
                    'message' => $exception->getMessage(),
                ]);

                continue;
            }

            $atual = $order->fresh();

            if ($atual && in_array($atual->status, [Order::STATUS_SHIPPED, Order::STATUS_COMPLETED], true)) {
                $fechados += $this->fechar($atual, $timeline, $seco, "{$atual->origin} confirmou {$atual->status} na reconsulta");
            }
        }

        $barra->finish();
        $this->newLine();
        $this->info("Concluído: {$fechados} baixa(s) de separação.");

        return self::SUCCESS;
    }

    private function fechar(Order $order, OrderFulfillmentTimeline $timeline, bool $seco, string $motivo): int
    {
        $this->line("  #{$order->id} ({$order->origin}) — {$motivo}");

        if ($seco) {
            return 1;
        }

        // packed_at recebe a data do pedido, não now(): a separação
        // aconteceu lá atrás, e carimbar hoje faria relatório de
        // produtividade contar semanas de trabalho no dia da varredura.
        $order->forceFill(['packed_at' => $order->updated_at ?? $order->created_at])->save();

        $timeline->record(
            $order,
            OrderFulfillmentEvent::STEP_ORDER_PACKED,
            OrderFulfillmentEvent::STATUS_SUCCESS,
            "Baixa automática de separação — {$motivo}.",
        );

        return 1;
    }
}
