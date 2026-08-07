<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cria um Pedido "na mão" pela tela do admin, pra vendas que não passam
 * pelo checkout do site nem por uma integração de marketplace conectada
 * (ex: canal ainda sem client_secret configurado, venda combinada por
 * fora). Existe separado de OrderImportService::createOrder() porque o
 * mapeamento de item aqui é direto — o admin escolhe o produto real do
 * catálogo na tela, sem depender de ProductChannelListing (external_id →
 * produto), que é exatamente o passo que faltou e gerou um chute errado
 * de produto no pedido Amazon #184 (criado via tinker antes desta tela
 * existir).
 */
class ManualOrderService
{
    public function __construct(
        private readonly StockManager $stock,
        private readonly OrderFulfillmentTimeline $timeline,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Order
    {
        try {
            return DB::transaction(function () use ($data) {
                $items = $data['items'];
                $subtotal = round(array_sum(array_map(
                    fn (array $item) => $item['quantity'] * $item['unit_price'],
                    $items,
                )), 2);
                $shippingCost = $data['shipping_cost'] ?? 0;

                $order = Order::create([
                    'user_id' => null,
                    'status' => $data['status'],
                    'origin' => $data['origin'],
                    'external_order_id' => $data['external_order_id'] ?? null,
                    'buyer_document' => $data['buyer_document'],
                    'shipping_name' => $data['buyer_name'],
                    'shipping_phone' => $data['buyer_phone'],
                    'shipping_email' => $data['buyer_email'] ?? null,
                    'shipping_whatsapp' => $data['buyer_whatsapp'] ?? null,
                    'shipping_zip' => $data['shipping_zip'],
                    'shipping_street' => $data['shipping_street'],
                    'shipping_number' => $data['shipping_number'],
                    'shipping_complement' => $data['shipping_complement'] ?? null,
                    'shipping_neighborhood' => $data['shipping_neighborhood'],
                    'shipping_city' => $data['shipping_city'],
                    'shipping_state' => $data['shipping_state'],
                    'subtotal' => $subtotal,
                    'shipping_cost' => $shippingCost,
                    'total' => round($subtotal + $shippingCost, 2),
                ]);

                $this->timeline->record($order, OrderFulfillmentEvent::STEP_WEBHOOK_RECEIVED, OrderFulfillmentEvent::STATUS_SUCCESS, "Pedido criado manualmente pelo admin ({$data['origin']})");

                foreach ($items as $item) {
                    $product = Product::findOrFail($item['product_id']);

                    $order->items()->create([
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'product_price' => $item['unit_price'],
                        'quantity' => $item['quantity'],
                        'subtotal' => round($item['unit_price'] * $item['quantity'], 2),
                    ]);

                    $this->stock->adjust(
                        $product,
                        -$item['quantity'],
                        StockMovement::TYPE_SALE,
                        reason: "Venda manual — {$data['origin']}",
                        reference: $order,
                    );
                }

                $this->timeline->record($order, OrderFulfillmentEvent::STEP_STOCK_UPDATED, OrderFulfillmentEvent::STATUS_SUCCESS, 'Estoque central debitado para todos os itens');

                // Nem toda venda manual já está paga (ex: cadastrada antes
                // da confirmação chegar) — só dispara a nota pros status
                // que realmente significam "dinheiro já recebido", mesmo
                // gatilho que OrderImportService usa pro import automático.
                if (in_array($data['status'], [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED], true)) {
                    GenerateInvoiceJob::dispatch($order->id)->afterCommit();
                }

                return $order;
            });
        } catch (QueryException $exception) {
            if ($exception->getCode() === '23000' && ! empty($data['external_order_id'])) {
                throw new RuntimeException('Já existe um pedido para esse canal com esse ID externo.', previous: $exception);
            }

            throw $exception;
        }
    }
}
