<?php

namespace App\Modules\Fiscal\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockManager;
use Illuminate\Support\Facades\DB;

/**
 * Pedido explícito 2026-08-09: menu de emissão manual de nota fiscal, item
 * por item podendo ser produto do catálogo OU serviço/produto digitado na
 * hora (empresa tem 2 CNAEs). Separado de ManualOrderService (que exige
 * product_id em todo item e sempre debita estoque — certo pra "registrar
 * uma venda que já aconteceu em outro canal", errado pra "emitir nota de um
 * serviço que nunca teve estoque nenhum"): aqui só debita estoque quando o
 * item É de fato um produto real do catálogo.
 */
class InvoiceEmissionService
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
        return DB::transaction(function () use ($data) {
            $items = $data['items'];
            $subtotal = round(array_sum(array_map(
                fn (array $item) => $item['quantity'] * $item['unit_price'],
                $items,
            )), 2);

            $order = Order::create([
                'user_id' => null,
                'status' => Order::STATUS_COMPLETED,
                'origin' => Order::ORIGIN_MANUAL_INVOICE,
                'buyer_document' => $data['buyer_document'],
                'shipping_name' => $data['buyer_name'],
                'shipping_phone' => $data['buyer_phone'] ?? '',
                'shipping_email' => $data['buyer_email'] ?? null,
                'shipping_zip' => $data['shipping_zip'],
                'shipping_street' => $data['shipping_street'],
                'shipping_number' => $data['shipping_number'],
                'shipping_complement' => $data['shipping_complement'] ?? null,
                'shipping_neighborhood' => $data['shipping_neighborhood'],
                'shipping_city' => $data['shipping_city'],
                'shipping_state' => $data['shipping_state'],
                'subtotal' => $subtotal,
                'shipping_cost' => 0,
                'total' => $subtotal,
            ]);

            $this->timeline->record($order, OrderFulfillmentEvent::STEP_WEBHOOK_RECEIVED, OrderFulfillmentEvent::STATUS_SUCCESS, 'Nota fiscal avulsa criada manualmente pelo admin');

            foreach ($items as $item) {
                if (! empty($item['product_id'])) {
                    $product = Product::findOrFail($item['product_id']);

                    $order->items()->create([
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'product_price' => $item['unit_price'],
                        'quantity' => $item['quantity'],
                        'subtotal' => round($item['unit_price'] * $item['quantity'], 2),
                        'item_type' => OrderItem::TYPE_PRODUCT,
                    ]);

                    $this->stock->adjust(
                        $product,
                        -$item['quantity'],
                        StockMovement::TYPE_SALE,
                        reason: 'Nota fiscal avulsa emitida manualmente',
                        reference: $order,
                    );

                    continue;
                }

                // Item digitado na hora — produto fora do catálogo ou
                // serviço avulso. Sem product_id, sem debitar estoque (não
                // existe estoque pra rastrear nesse caso).
                $order->items()->create([
                    'product_id' => null,
                    'product_name' => $item['description'],
                    'product_price' => $item['unit_price'],
                    'quantity' => $item['quantity'],
                    'subtotal' => round($item['unit_price'] * $item['quantity'], 2),
                    'item_type' => $item['item_type'] ?? OrderItem::TYPE_SERVICE,
                    'ncm' => $item['ncm'],
                    'cest' => $item['cest'] ?? null,
                    'cfop' => $item['cfop'],
                    'cfop_outros_estados' => $item['cfop_outros_estados'] ?? null,
                    'origem_mercadoria' => $item['origem_mercadoria'] ?? 0,
                    'gtin' => $item['gtin'] ?? null,
                    'unidade_tributavel' => $item['unidade_tributavel'] ?? 'UN',
                    'icms_situacao_tributaria' => $item['icms_situacao_tributaria'],
                    'pis_situacao_tributaria' => $item['pis_situacao_tributaria'],
                    'pis_aliquota' => $item['pis_aliquota'] ?? 0,
                    'cofins_situacao_tributaria' => $item['cofins_situacao_tributaria'],
                    'cofins_aliquota' => $item['cofins_aliquota'] ?? 0,
                    'percentual_aproximado_tributos' => $item['percentual_aproximado_tributos'] ?? 0,
                ]);
            }

            $this->timeline->record($order, OrderFulfillmentEvent::STEP_STOCK_UPDATED, OrderFulfillmentEvent::STATUS_SUCCESS, 'Estoque debitado para os itens que são produto real do catálogo');

            GenerateInvoiceJob::dispatch($order->id)->afterCommit();

            return $order;
        });
    }
}
