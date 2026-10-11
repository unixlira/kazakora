<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Support\ImportadorDemonstrativoTikTok;
use Illuminate\Console\Command;

/** Grava a taxa real (do extrato) em todo pedido do TikTok já liquidado (2026-10-11). */
class TaxasReaisTikTok extends Command
{
    protected $signature = 'tiktok:taxas-reais';

    protected $description = 'Taxa real do extrato do TikTok em todo pedido liquidado (substitui a estimativa)';

    public function handle(ImportadorDemonstrativoTikTok $importador): int
    {
        $this->info($importador->aplicarTaxasReais().' pedido(s) com taxa real do extrato.');

        return self::SUCCESS;
    }
}
