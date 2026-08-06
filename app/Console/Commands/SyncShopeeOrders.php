<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Drivers\ShopeeDriver;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\OrderImportService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Backfill de todos os pedidos da loja na Shopee — mesmo pedido/motivo do
 * SyncMercadoLivreOrders (a loja vendia na Shopee antes da conexão com o
 * Kazakora ter sido feita hoje, 2026-08-06 — nenhum desses pedidos nunca
 * chegou por webhook). Idempotente, seguro rodar de novo.
 */
class SyncShopeeOrders extends Command
{
    protected $signature = 'orders:sync-shopee {--dias=365 : Quantos dias pra trás verificar}';

    protected $description = 'Importa/sincroniza todos os pedidos da Shopee pro banco local';

    public function handle(ShopeeDriver $driver, OrderImportService $importer): int
    {
        $account = MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)->first();

        if (! $account?->isConnected()) {
            $this->error('Shopee não está conectada.');

            return self::FAILURE;
        }

        $lookbackDays = (int) $this->option('dias');

        $this->info("Buscando pedidos dos últimos {$lookbackDays} dias...");
        $sns = $driver->listAllOrderSns($lookbackDays);
        $this->info(count($sns).' pedido(s) encontrado(s) na loja.');

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
