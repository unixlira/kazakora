<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Drivers\ShopeeDriver;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\OrderImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Importa/sincroniza os pedidos da Shopee pro banco local, filtrado por
 * data direto na API (não busca tudo pra filtrar depois) — pedido real do
 * usuário 2026-08-06 ("só o mês atual", refeito no mesmo dia depois de um
 * primeiro backfill sem escopo de data). Idempotente, seguro rodar de novo.
 */
class SyncShopeeOrders extends Command
{
    protected $signature = 'orders:sync-shopee {--desde= : Data inicial (Y-m-d), padrão: início do mês corrente} {--ate= : Data final (Y-m-d), padrão: agora}';

    protected $description = 'Importa/sincroniza os pedidos da Shopee pro banco local, por período';

    public function handle(ShopeeDriver $driver, OrderImportService $importer): int
    {
        $account = MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)->first();

        if (! $account?->isConnected()) {
            $this->error('Shopee não está conectada.');

            return self::FAILURE;
        }

        $from = $this->option('desde') ? Carbon::parse($this->option('desde'))->startOfDay() : now()->startOfMonth();
        $to = $this->option('ate') ? Carbon::parse($this->option('ate'))->endOfDay() : now();

        $this->info("Buscando pedidos de {$from->toDateString()} até {$to->toDateString()}...");
        $sns = $driver->listOrderSns($from, $to);
        $this->info(count($sns).' pedido(s) encontrado(s) no período.');

        $imported = 0;
        $failed = 0;

        foreach ($sns as $sn) {
            try {
                $importer->import(MarketplaceAccount::CHANNEL_SHOPEE, $sn);
                $imported++;
            } catch (Throwable $exception) {
                $failed++;
                $this->warn("Pedido {$sn}: {$exception->getMessage()}");
            }
        }

        $this->info("Concluído: {$imported} sincronizado(s), {$failed} com erro.");

        return self::SUCCESS;
    }
}
