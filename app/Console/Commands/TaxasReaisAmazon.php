<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Support\ImportadorRelatorioAmazon;
use Illuminate\Console\Command;

/** Grava a taxa real (do relatório de pagamentos) em todo pedido da Amazon já liquidado (2026-10-12). */
class TaxasReaisAmazon extends Command
{
    protected $signature = 'amazon:taxas-reais';

    protected $description = 'Taxa real do relatório de pagamentos da Amazon em todo pedido liquidado';

    public function handle(ImportadorRelatorioAmazon $importador): int
    {
        $this->info($importador->aplicarTaxasReais().' pedido(s) com taxa real da Amazon.');

        return self::SUCCESS;
    }
}
