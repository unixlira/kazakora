<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\OrderImportService;
use App\Services\MercadoLivre\MercadoLivreAuthService;
use App\Services\MercadoLivre\Services\OrderService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Backfill de todos os pedidos do vendedor no Mercado Livre — pedido real
 * do usuário 2026-08-06 ("importar todos pedidos do mercado livre e
 * valores"). Idempotente de propósito: OrderImportService::import() já
 * detecta pedido existente (origin+external_order_id) e só sincroniza
 * status, nunca duplica nem debita estoque de novo — seguro rodar quantas
 * vezes quiser, inclusive como reconciliação periódica futura.
 */
class SyncMercadoLivreOrders extends Command
{
    protected $signature = 'orders:sync-mercadolivre';

    protected $description = 'Importa/sincroniza todos os pedidos do Mercado Livre pro banco local';

    public function handle(MercadoLivreAuthService $auth, OrderService $orders, OrderImportService $importer): int
    {
        $account = MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)->first();
        $token = $auth->currentToken();

        if (! $account?->isConnected() || ! $token) {
            $this->error('Mercado Livre não está conectado.');

            return self::FAILURE;
        }

        $this->info('Buscando todos os pedidos do vendedor...');
        $ids = $orders->listAllOrderIds((int) $token->ml_user_id);
        $this->info(count($ids).' pedido(s) encontrado(s) na conta.');

        $imported = 0;
        $failed = 0;

        foreach ($ids as $id) {
            try {
                $importer->import(MarketplaceAccount::CHANNEL_MERCADO_LIVRE, $id);
                $imported++;
            } catch (Throwable $exception) {
                $failed++;
                $this->warn("Pedido {$id}: {$exception->getMessage()}");
            }
        }

        $this->info("Concluído: {$imported} sincronizado(s), {$failed} com erro.");

        return self::SUCCESS;
    }
}
