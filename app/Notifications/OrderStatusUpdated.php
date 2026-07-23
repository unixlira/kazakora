<?php

namespace App\Notifications;

use App\Modules\Checkout\Models\Order;
use Illuminate\Notifications\Notification;

class OrderStatusUpdated extends Notification
{
    private const STATUS_LABELS = [
        Order::STATUS_PENDING => 'Pendente',
        Order::STATUS_PAID => 'Pago',
        Order::STATUS_SHIPPED => 'Enviado',
        Order::STATUS_COMPLETED => 'Concluído',
        Order::STATUS_CANCELLED => 'Cancelado',
    ];

    public function __construct(private readonly Order $order) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $label = self::STATUS_LABELS[$this->order->status] ?? $this->order->status;

        return [
            'order_id' => $this->order->id,
            'status' => $this->order->status,
            'message' => "Pedido #{$this->order->id} agora está: {$label}",
        ];
    }
}
