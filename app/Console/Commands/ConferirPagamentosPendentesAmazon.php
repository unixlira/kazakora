<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Drivers\AmazonDriver;
use App\Modules\Marketplace\Support\AmazonPagamentoPendenteService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pedidos da Amazon em "aguardando pagamento" (boleto): confere cada um na
 * SP-API de 24 em 24 horas e libera, cancela ou segura conforme a regra de
 * AmazonPagamentoPendenteService. Roda de hora em hora para que cada pedido
 * seja conferido 24 h depois da última vez, não todos no mesmo horário.
 */
class ConferirPagamentosPendentesAmazon extends Command
{
    protected $signature = 'amazon:pagamentos-pendentes {--dias=30 : Janela de pedidos a conferir} {--todos : Confere agora, sem esperar 24 h}';

    protected $description = 'Confere na Amazon os pedidos com pagamento pendente: libera os pagos e cancela os vencidos';

    public function handle(AmazonDriver $driver, AmazonPagamentoPendenteService $service): int
    {
        if (! $driver->isConfigured()) {
            $this->info('SP-API da Amazon não conectada: nada a conferir.');

            return self::SUCCESS;
        }

        $pedidos = Order::query()
            ->where('origin', Order::ORIGIN_AMAZON)
            ->where('status', Order::STATUS_AWAITING_PAYMENT)
            ->whereNotNull('external_order_id')
            ->where('created_at', '>=', now()->subDays((int) $this->option('dias')))
            ->orderBy('created_at')
            ->get();

        $resumo = [];

        foreach ($pedidos as $order) {
            if (! $this->option('todos') && ! $service->precisaConferir($order)) {
                continue;
            }

            try {
                $resultado = $service->conferir($order);
            } catch (Throwable $exception) {
                $resultado = 'erro';
                $this->warn("Pedido #{$order->id} ({$order->external_order_id}): {$exception->getMessage()}");
            }

            $resumo[$resultado] = ($resumo[$resultado] ?? 0) + 1;
        }

        $this->info($pedidos->count().' pedido(s) aguardando pagamento. '.(collect($resumo)->map(fn ($n, $r) => "{$r}: {$n}")->implode(', ') ?: 'nenhum conferido agora.'));

        return self::SUCCESS;
    }
}
