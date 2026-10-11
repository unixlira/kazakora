<?php

namespace App\Modules\Suporte\Jobs;

use App\Modules\Suporte\Models\Chamado;
use App\Modules\Suporte\Services\ManuelaSuporte;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** A Manuela responde a última mensagem do cliente no chat de suporte (fila default). */
class ResponderComManuela implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(public readonly int $chamadoId)
    {
    }

    public function handle(ManuelaSuporte $manuela): void
    {
        $chamado = Chamado::find($this->chamadoId);

        if ($chamado && ! $chamado->humano_ativo && in_array($chamado->status, Chamado::ATIVOS, true)) {
            $manuela->responder($chamado);
        }
    }
}
