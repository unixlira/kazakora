<?php

namespace App\Modules\Fiscal\Jobs;

use App\Modules\Fiscal\Services\DevolucaoFiscalAutomatica;
use App\Modules\Marketplace\Models\MarketplaceReturn;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Prepara / emite / descarta a NF-e de devolução de um caso (2026-10-11). */
class ProcessarDevolucaoFiscal implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly int $casoId)
    {
    }

    public function handle(DevolucaoFiscalAutomatica $servico): void
    {
        $caso = MarketplaceReturn::query()->find($this->casoId);

        if ($caso) {
            $servico->processar($caso);
        }
    }
}
