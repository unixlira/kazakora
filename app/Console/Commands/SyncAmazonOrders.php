<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Drivers\AmazonDriver;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\OrderImportService;
use App\Services\Amazon\AmazonClient;
use App\Services\Bling\BlingOrderService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Importa/sincroniza os pedidos da Amazon pro banco local, por período —
 * mesmo padrão já usado pro Mercado Livre/Shopee (orders:sync-mercadolivre/
 * orders:sync-shopee). Idempotente: OrderImportService::import() já detecta
 * pedido existente e só sincroniza status, nunca duplica.
 *
 * Direto pela SP-API (AMAZON_DIRETO=true, desde 16/10/2026): searchOrders da Orders
 * API v2026-01-01 (a v0 sai do ar em 27/03/2027), paginando por
 * paginationToken. No fluxo via Bling (sem AMAZON_DIRETO, ou SP-API
 * desconectada), lista os pedidos da loja Amazon NO BLING, como o
 * orders:sync-tiktok.
 */
class SyncAmazonOrders extends Command
{
    protected $signature = 'orders:sync-amazon {--desde= : Data inicial (Y-m-d), padrão: início do mês corrente} {--ate= : Data final (Y-m-d), padrão: agora}';

    protected $description = 'Importa/sincroniza os pedidos da Amazon pro banco local, por período';

    public function handle(AmazonClient $client, OrderImportService $importer, BlingOrderService $blingOrders): int
    {
        $account = MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_AMAZON)->first();
        $blingLojaId = $blingOrders->amazonLojaId();

        if (! $account?->isConnected() && ! $blingLojaId) {
            $this->error('Amazon não está conectada (nem por SP-API, nem por loja do Bling — BLING_AMAZON_LOJA_ID).');

            return self::FAILURE;
        }

        $from = $this->option('desde') ? Carbon::parse($this->option('desde'))->startOfDay() : now()->startOfMonth();
        $to = $this->option('ate') ? Carbon::parse($this->option('ate'))->endOfDay() : now();

        // A SP-API recusa (HTTP 400 InvalidInput, conferido ao vivo em
        // 2026-10-16) CreatedBefore a menos de 2 minutos do pedido — e o
        // padrão aqui era "agora", então toda rodada de hora em hora falharia
        // assim que a conta fosse reconectada.
        $to = $to->min(now()->subMinutes(3));

        if (app(AmazonDriver::class)->viaBling() || ! $account?->isConnected()) {
            $this->info("Buscando pedidos da Amazon (via Bling, loja {$blingLojaId}) de {$from->toDateString()} até {$to->toDateString()}...");

            return $this->importAll($importer, $blingOrders->listRecentOrderNumbers($from, $to, $blingLojaId));
        }

        $this->info("Buscando pedidos de {$from->toDateString()} até {$to->toDateString()}...");

        $orderIds = [];
        $paginationToken = null;

        do {
            // A página seguinte repete os filtros junto com o token (sem eles
            // a Amazon recusa: "One and only one of createdAfter or
            // lastUpdatedAfter must be provided", conferido em 16/10/2026).
            $query = array_filter([
                'marketplaceIds' => config('services.amazon.marketplace_id'),
                'createdAfter' => $from->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                'createdBefore' => $to->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
                'maxResultsPerPage' => 100,
                'paginationToken' => $paginationToken,
            ]);

            $response = $client->get('/orders/2026-01-01/orders', $query);

            foreach ($response['orders'] ?? [] as $order) {
                if (isset($order['orderId'])) {
                    $orderIds[] = (string) $order['orderId'];
                }
            }

            $paginationToken = $response['pagination']['nextToken'] ?? null;
        } while ($paginationToken);

        return $this->importAll($importer, $orderIds);
    }

    /**
     * @param  array<int, string>  $orderIds
     */
    private function importAll(OrderImportService $importer, array $orderIds): int
    {
        $this->info(count($orderIds).' pedido(s) encontrado(s) no período.');

        $imported = 0;
        $failed = 0;

        foreach ($orderIds as $orderId) {
            try {
                $importer->import(MarketplaceAccount::CHANNEL_AMAZON, $orderId);
                $imported++;
            } catch (Throwable $exception) {
                $failed++;
                $this->warn("Pedido {$orderId}: {$exception->getMessage()}");
            }
        }

        $this->info("Concluído: {$imported} sincronizado(s), {$failed} com erro.");

        return self::SUCCESS;
    }
}
