<?php

namespace App\Notifications;

use App\Modules\Checkout\Models\Order;
use Illuminate\Notifications\Notification;

class InvoiceIssuanceFailedNotification extends Notification
{
    public function __construct(
        private readonly Order $order,
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
            'order_id' => $this->order->id,
            'reason' => $this->reason,
            'message' => "Falha ao emitir a NF-e do pedido #{$this->order->id}: {$this->reason}",
        ];
    }
}
