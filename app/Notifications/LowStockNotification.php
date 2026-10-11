<?php

namespace App\Notifications;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Notifications\Notification;

/**
 * Pedido explícito 2026-08-16: alerta de estoque baixo (produtos com poucas
 * unidades sobrando, "pra comprar") — dispara como modal no login (ver
 * AuthenticatedSessionController::store()) além de aparecer normal na
 * sineta de notificações. Uma instância por checagem cobre TODOS os
 * produtos baixos de uma vez (não 1 notificação por produto) — LowStock
 * AlertService::checkAndNotify() já cuida de não duplicar enquanto a
 * anterior continuar sem lida.
 */
class LowStockNotification extends Notification
{
    /**
     * @param  Collection<int, \App\Modules\Catalog\Models\Product>  $products
     */
    public function __construct(private readonly Collection $products)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $names = $this->products->pluck('name')->implode(', ');

        return [
            'products' => $this->products->map(fn ($product) => [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'stock' => $product->stock,
            ])->all(),
            'message' => $this->products->count() === 1
                ? "Estoque baixo: \"{$names}\" está acabando."
                : "Estoque baixo: {$this->products->count()} produtos estão acabando ({$names}).",
        ];
    }
}
