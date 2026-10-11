<?php

namespace App\Notifications;

use App\Modules\Catalog\Models\Product;
use Illuminate\Notifications\Notification;

class OversellDetectedNotification extends Notification
{
    public function __construct(
        private readonly Product $product,
        private readonly int $requested,
        private readonly int $available,
        private readonly string $reason,
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
            'product_name' => $this->product->name,
            'requested' => $this->requested,
            'available' => $this->available,
            'reason' => $this->reason,
            'message' => "Estoque negativo evitado: \"{$this->product->name}\" pedia {$this->requested} un., só havia {$this->available}. Origem: {$this->reason}. Estoque zerado — trate manualmente.",
        ];
    }
}
