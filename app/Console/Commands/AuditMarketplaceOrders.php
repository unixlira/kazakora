<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Jobs\AuditMarketplaceOrdersJob;
use Illuminate\Console\Command;

/**
 * Só enfileira AuditMarketplaceOrdersJob — pedido explícito 2026-08-15
 * ("faça assíncrono"). Não roda a reconciliação na hora nem bloqueia o
 * terminal: o queue worker (cron a cada minuto, ver QUEUE_CONNECTION no
 * .env) processa em background. Ver AuditMarketplaceOrdersJob pro que de
 * fato acontece e o log 'marketplace.audit.*' pro progresso/resultado.
 */
class AuditMarketplaceOrders extends Command
{
    protected $signature = 'orders:audit {--dias=90 : Quantos dias pra trás reconciliar}';

    protected $description = 'Enfileira (assíncrono) a reconciliação dos pedidos dos últimos N dias em todos os marketplaces conectados';

    public function handle(): int
    {
        $days = (int) $this->option('dias');

        AuditMarketplaceOrdersJob::dispatch($days);

        $this->info("Auditoria de {$days} dias enfileirada — acompanhe em storage/logs/laravel.log ('marketplace.audit.*').");

        return self::SUCCESS;
    }
}
