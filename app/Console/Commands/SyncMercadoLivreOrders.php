<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\OrderImportService;
use App\Services\MercadoLivre\MercadoLivreAuthService;
use App\Services\MercadoLivre\Services\OrderService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Importa/sincroniza os pedidos do Mercado Livre pro banco local, filtrado
 * por data direto na API (não busca tudo pra filtrar depois) — pedido real
 * do usuário 2026-08-06 ("só o mês atual", refeito no mesmo dia depois de
 * um primeiro backfill sem escopo de data). Idempotente de propósito:
 * OrderImportService::import() já detecta pedido existente
 * (origin+external_order_id) e só sincroniza status/data, nunca duplica
 * nem debita estoque de novo — seguro rodar quantas vezes quiser, inclusive
 * como reconciliação periódica.
 */
class SyncMercadoLivreOrders extends Command
{
    protected $signature = 'orders:sync-mercadolivre {--desde= : Data inicial (Y-m-d), padrão: início do mês corrente} {--ate= : Data final (Y-m-d), padrão: agora}';

    protected $description = 'Importa/sincroniza os pedidos do Mercado Livre pro banco local, por período';

    public function handle(MercadoLivreAuthService $auth, OrderService $orders, OrderImportService $importer): int
    {
        $account = MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)->first();
        $token = $auth->currentToken();

        if (! $account?->isConnected() || ! $token) {
            $this->error('Mercado Livre não está conectado.');

            return self::FAILURE;
        }

        $from = $this->option('desde') ? Carbon::parse($this->option('desde'))->startOfDay() : now()->startOfMonth();
        $to = $this->option('ate') ? Carbon::parse($this->option('ate'))->endOfDay() : now();

        $this->info("Buscando pedidos de {$from->toDateString()} até {$to->toDateString()}...");
        $ids = $orders->listOrderIds((int) $token->ml_user_id, $from, $to);
        $this->info(count($ids).' pedido(s) encontrado(s) no período.');

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
