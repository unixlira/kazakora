<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Limpa as repetições antigas da linha do tempo dos pedidos (gravadas antes
 * do contador `repeticoes`): cada sequência de eventos iguais vira a
 * primeira linha com o contador somado e a data da última vez. Sem
 * --executar só conta o que faria.
 */
class CompactarLinhaDoTempo extends Command
{
    protected $signature = 'pedidos:compactar-linha-do-tempo {--executar : apaga as linhas repetidas de verdade}';

    protected $description = 'Junta os eventos repetidos da linha do tempo dos pedidos num só, com contador.';

    public function handle(): int
    {
        $executar = (bool) $this->option('executar');
        $pedidos = OrderFulfillmentEvent::query()
            ->select('order_id')
            ->groupBy('order_id', 'step', 'status', 'message')
            ->havingRaw('count(*) > 1')
            ->pluck('order_id')
            ->unique()
            ->values();

        $apagadas = 0;

        foreach ($pedidos as $orderId) {
            $eventos = OrderFulfillmentEvent::query()
                ->where('order_id', $orderId)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get(['id', 'order_id', 'step', 'status', 'message', 'repeticoes', 'created_at', 'updated_at']);

            [$ficam, $repetidos] = OrderFulfillmentEvent::compactar($eventos);

            if ($repetidos === []) {
                continue;
            }

            $apagadas += count($repetidos);

            if (! $executar) {
                continue;
            }

            DB::transaction(function () use ($ficam, $repetidos) {
                foreach ($ficam as $evento) {
                    if ($evento->isDirty(['repeticoes', 'updated_at'])) {
                        OrderFulfillmentEvent::query()->whereKey($evento->id)->update([
                            'repeticoes' => $evento->repeticoes,
                            'updated_at' => $evento->updated_at,
                        ]);
                    }
                }

                foreach (array_chunk($repetidos, 1000) as $lote) {
                    OrderFulfillmentEvent::query()->whereKey($lote)->delete();
                }
            });
        }

        $this->info(($executar ? 'Linhas repetidas apagadas: ' : 'Linhas repetidas (nada apagado, use --executar): ')
            ."{$apagadas} em {$pedidos->count()} pedido(s).");

        return self::SUCCESS;
    }
}
