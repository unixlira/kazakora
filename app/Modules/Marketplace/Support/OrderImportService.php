<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Support\OrderPaymentFinalizer;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockManager;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Models\ProductChannelListing;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Canal-agnóstico de propósito: só fala com MarketplaceChannelDriver
 * (interface), nunca com a API de um canal específico. Cada driver já
 * devolve o pedido no formato comum declarado em
 * MarketplaceChannelDriver::importOrder() — esse serviço só sabe transformar
 * esse formato em Order/OrderItem e debitar o estoque central, do mesmo
 * jeito que o checkout do site já faz.
 */
class OrderImportService
{
    public function __construct(
        private readonly MarketplaceDriverManager $manager,
        private readonly StockManager $stock,
        private readonly OrderPaymentFinalizer $finalizer,
    ) {
    }

    public function import(string $channel, string $externalOrderId): Order
    {
        $data = $this->manager->driver($channel)->importOrder($externalOrderId);

        $existing = Order::query()
            ->where('origin', $channel)
            ->where('external_order_id', $data['external_order_id'])
            ->first();

        if ($existing) {
            return $this->syncStatus($existing, $data['status']);
        }

        try {
            return $this->createOrder($channel, $data);
        } catch (QueryException $exception) {
            // Reentrega de webhook quase simultânea pode passar pelo check
            // de existência acima antes do outro processo commitar — o
            // índice único (origin, external_order_id) pega isso na hora do
            // insert. Trata como já importado em vez de estourar erro.
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }

            $order = Order::query()
                ->where('origin', $channel)
                ->where('external_order_id', $data['external_order_id'])
                ->firstOrFail();

            return $this->syncStatus($order, $data['status']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createOrder(string $channel, array $data): Order
    {
        return DB::transaction(function () use ($channel, $data) {
            $order = Order::create([
                'user_id' => null,
                'status' => $data['status'],
                'origin' => $channel,
                'external_order_id' => $data['external_order_id'],
                'shipping_name' => $data['buyer_name'],
                'shipping_phone' => $data['buyer_phone'] ?? 'Não informado',
                'shipping_zip' => $data['shipping_zip'],
                'shipping_street' => $data['shipping_street'],
                'shipping_number' => $data['shipping_number'],
                'shipping_complement' => $data['shipping_complement'],
                'shipping_neighborhood' => $data['shipping_neighborhood'],
                'shipping_city' => $data['shipping_city'],
                'shipping_state' => $data['shipping_state'],
                'subtotal' => $data['subtotal'],
                'shipping_cost' => $data['shipping_cost'],
                'total' => $data['total'],
            ]);

            foreach ($data['items'] as $item) {
                $listing = ProductChannelListing::query()
                    ->where('channel', $channel)
                    ->where('external_id', $item['external_id'])
                    ->first();
                $product = $listing?->product;

                $order->items()->create([
                    'product_id' => $product?->id,
                    'product_name' => $product?->name ?? "Item {$item['external_id']} (sem produto local mapeado)",
                    'product_price' => $item['unit_price'],
                    'quantity' => $item['quantity'],
                    'subtotal' => round($item['unit_price'] * $item['quantity'], 2),
                ]);

                if (! $product) {
                    Log::warning('marketplace.order_import.unmapped_item', [
                        'channel' => $channel,
                        'external_order_id' => $data['external_order_id'],
                        'item_external_id' => $item['external_id'],
                    ]);

                    continue;
                }

                $this->stock->adjust(
                    $product,
                    -$item['quantity'],
                    StockMovement::TYPE_SALE,
                    reason: 'Venda importada — '.$channel,
                    reference: $order,
                );
            }

            return $order;
        });
    }

    private function syncStatus(Order $order, string $newStatus): Order
    {
        if ($order->status === $newStatus) {
            return $order;
        }

        $wasCancelled = $order->status === Order::STATUS_CANCELLED;

        $order->update(['status' => $newStatus]);

        if ($newStatus === Order::STATUS_CANCELLED && ! $wasCancelled) {
            $this->finalizer->restoreStockIfNeeded($order, 'Pedido cancelado no canal de origem');
        }

        return $order;
    }
}
