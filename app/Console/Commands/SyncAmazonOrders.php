<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Support\OrderImportService;
use App\Services\Amazon\AmazonClient;
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
 * Sempre pela SP-API (Lira, 04/11/2026: o Bling é só do TikTok Shop).
 * Rede de segurança do aviso ORDER_CHANGE da fila SQS
 * (amazon:notificacoes-ouvir): recupera o que entrou enquanto o servidor
 * esteve fora ou o aviso falhou.
 */
class SyncAmazonOrders extends Command
{
    protected $signature = 'orders:sync-amazon {--desde= : Data inicial (Y-m-d), padrão: início do mês corrente} {--ate= : Data final (Y-m-d), padrão: agora}';

    protected $description = 'Importa/sincroniza os pedidos da Amazon pro banco local, por período';

    public function handle(AmazonClient $client, OrderImportService $importer): int
    {
        $account = MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_AMAZON)->first();

        if (! $account?->isConnected()) {
            $this->error('Amazon não está conectada pela SP-API.');

            return self::FAILURE;
        }

        $from = $this->option('desde') ? Carbon::parse($this->option('desde'))->startOfDay() : now()->startOfMonth();
        $to = $this->option('ate') ? Carbon::parse($this->option('ate'))->endOfDay() : now();

        // A SP-API recusa (HTTP 400 InvalidInput, conferido ao vivo em
        // 2026-10-16) CreatedBefore a menos de 2 minutos do pedido — e o
        // padrão aqui era "agora", então toda rodada de hora em hora falharia
        // assim que a conta fosse reconectada.
        $to = $to->min(now()->subMinutes(3));

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

        // Pedido segurado em "aguardando pagamento" (AmazonDriver::
        // comPagamentoConferidoNaAmazon()) pode ter sido criado antes da
        // janela de 2 dias da rodada agendada (Pix/boleto demorado): sem
        // isto ele nunca seria conferido de novo e não sairia nunca.
        $aguardando = Order::query()
            ->where('origin', Order::ORIGIN_AMAZON)
            ->where('status', Order::STATUS_AWAITING_PAYMENT)
            ->where('created_at', '>=', now()->subDays(7))
            ->pluck('external_order_id')
            ->all();

        return $this->importAll($importer, array_values(array_unique([...$orderIds, ...$aguardando])));
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
