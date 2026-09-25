<?php

namespace App\Console\Commands;

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
 * `getOrders` (Orders API v0) pagina via NextToken, sem o limite de janela
 * de 15 dias que a Shopee tem — uma chamada por página cobre o período
 * inteiro pedido.
 *
 * Sem SP-API conectada (o caso real desde 2026-09-25: a Amazon está
 * conectada só ao Bling), lista os pedidos da loja Amazon NO BLING — mesmo
 * papel do orders:sync-tiktok: rede de segurança do webhook, que o Bling
 * desliga depois de 3 dias falhando entrega. Recupera o que entrou
 * enquanto o servidor esteve fora.
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

        if (! $account?->isConnected()) {
            $this->info("Buscando pedidos da Amazon (via Bling, loja {$blingLojaId}) de {$from->toDateString()} até {$to->toDateString()}...");

            return $this->importAll($importer, $blingOrders->listRecentOrderNumbers($from, $to, $blingLojaId));
        }

        $this->info("Buscando pedidos de {$from->toDateString()} até {$to->toDateString()}...");

        $orderIds = [];
        $nextToken = null;

        do {
            $query = array_filter([
                'MarketplaceIds' => config('services.amazon.marketplace_id'),
                'CreatedAfter' => $from->toAtomString(),
                'CreatedBefore' => $to->toAtomString(),
                'NextToken' => $nextToken,
            ]);

            $response = $client->get('/orders/v0/orders', $query);
            $payload = $response['payload'] ?? [];

            foreach ($payload['Orders'] ?? [] as $order) {
                if (isset($order['AmazonOrderId'])) {
                    $orderIds[] = (string) $order['AmazonOrderId'];
                }
            }

            $nextToken = $payload['NextToken'] ?? null;
        } while ($nextToken);

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
