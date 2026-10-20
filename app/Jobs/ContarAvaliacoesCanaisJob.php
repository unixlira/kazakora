<?php

namespace App\Jobs;

use App\Modules\Marketplace\Support\ContagemAvaliacoesCanais;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Botão "Atualizar contagem" da tela Avaliações › Cadastro e controle: a
 * leitura do ML é uma chamada por anúncio, então roda na fila em vez de
 * segurar a página.
 */
class ContarAvaliacoesCanaisJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 600;

    public int $uniqueFor = 600;

    public function handle(ContagemAvaliacoesCanais $contagem): void
    {
        $contagem->atualizar();
    }
}
