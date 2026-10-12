<?php

namespace App\Console\Commands;

use App\Modules\BeautyKora\Services\ConciliarPagamentosHostinger;
use Illuminate\Console\Command;
use Throwable;

/**
 * Beauty Kora (pedido 2026-10-12): marca como pago o pedido que foi pago no
 * checkout da Hostinger, e como cancelado o que foi estornado. Roda a cada
 * 5 minutos (routes/console.php). Ver ConciliarPagamentosHostinger.
 */
class ConciliarPagamentosBeautyKora extends Command
{
    protected $signature = 'beautykora:conciliar-pagamentos';

    protected $description = 'Beauty Kora: marca como pagos os pedidos pagos no checkout da Hostinger';

    public function handle(ConciliarPagamentosHostinger $conciliador): int
    {
        if (! config('services.beautykora.hostinger.token')) {
            $this->warn('BEAUTYKORA_HOSTINGER_TOKEN não configurado: nada a fazer.');

            return self::SUCCESS;
        }

        try {
            $r = $conciliador->executar();
        } catch (Throwable $e) {
            report($e);
            $this->error('Falha ao consultar a Hostinger: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Pagos: {$r['pagos']}. Estornados: {$r['estornados']}.");
        if ($r['sem_par'] !== []) {
            $this->warn('Pagos na Hostinger sem pedido correspondente aqui: '.implode(', ', $r['sem_par']));
        }

        return self::SUCCESS;
    }
}
