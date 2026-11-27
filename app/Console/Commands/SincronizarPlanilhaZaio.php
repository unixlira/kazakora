<?php

namespace App\Console\Commands;

use App\Modules\Financeiro\Support\PlanilhaZaio;
use Illuminate\Console\Command;

/** Copia a planilha da Zaio para o painel financeiro (todo dia e sob demanda). */
class SincronizarPlanilhaZaio extends Command
{
    protected $signature = 'zaio:planilha';

    protected $description = 'Copia a planilha da Zaio (Shopee, TikTok, ML) para o painel financeiro';

    public function handle(PlanilhaZaio $planilha): int
    {
        foreach ($planilha->sincronizar() as $canal => $total) {
            $this->line("{$canal}: {$total} linhas");
        }

        return self::SUCCESS;
    }
}
