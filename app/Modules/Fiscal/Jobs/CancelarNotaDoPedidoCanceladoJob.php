<?php

namespace App\Modules\Fiscal\Jobs;

use App\Modules\Fiscal\Support\CancelamentoAutomaticoDaNota;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Webhook do canal cancelou o pedido: cancela a NF-e na hora, sem esperar a
 * rodada de 10 min (Lira, 2026-10-29). Erro vira alerta para checagem
 * (CancelamentoAutomaticoDaNota); a rodada de 10 min tenta de novo.
 */
class CancelarNotaDoPedidoCanceladoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Erro não volta para a fila: vira alerta e a rodada de 10 min tenta de novo. */
    public int $tries = 1;

    public function __construct(public readonly int $orderId)
    {
        $this->onQueue('nfe');
    }

    public function handle(CancelamentoAutomaticoDaNota $cancelamento): void
    {
        if (! CancelamentoAutomaticoDaNota::servidorDeProducao()) {
            return;
        }

        foreach ($cancelamento->casos($this->orderId) as $nota) {
            $cancelamento->cancelar($nota);
        }

        // Nota do Bling: completa o protocolo (XML ou SEFAZ) e cancela;
        // sem confirmação vira alerta (pedido do Lira em 12/11/2026).
        foreach ($cancelamento->semProtocolo($this->orderId) as $nota) {
            $cancelamento->cancelarSemProtocolo($nota);
        }
    }
}
