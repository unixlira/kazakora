<?php

namespace App\Notifications;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use Illuminate\Notifications\Notification;

/**
 * Disparada quando OrderImportService encontra um item de venda sem
 * produto local vinculado e importa automaticamente do canal (ver
 * ShopeeProductImportService::importSingle()) — o produto entra como
 * rascunho (is_active=false, sem dados fiscais) só pra desbloquear o
 * pedido; ainda precisa de revisão manual (NCM/CFOP/CEST etc.) antes da
 * nota fiscal e da etiqueta desse pedido saírem. Preencher os dados
 * fiscais do produto reprocessa a nota automaticamente — ver
 * ProductFiscalController::update().
 */
class ProductAutoImportedNotification extends Notification
{
    public function __construct(
        private readonly Product $product,
        private readonly Order $order,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'product_id' => $this->product->id,
            'order_id' => $this->order->id,
            'message' => "Produto \"{$this->product->name}\" foi importado automaticamente da Shopee pro pedido #{$this->order->id} (venda de um anúncio que ainda não existia no catálogo). Cadastre os dados fiscais dele pra liberar a nota e a etiqueta desse pedido.",
        ];
    }
}
