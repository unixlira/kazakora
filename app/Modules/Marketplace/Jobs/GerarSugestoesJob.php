<?php

namespace App\Modules\Marketplace\Jobs;

use App\Modules\Marketplace\Support\Sugestoes\GeradorDeSugestoes;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Botão "Rodar agora" das Sugestões do dia: a mesma rotina da madrugada,
 * na fila (a pesquisa de preço leva alguns minutos). Uma por vez.
 */
class GerarSugestoesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function handle(GeradorDeSugestoes $gerador): void
    {
        $gerador->gerar();
    }
}
