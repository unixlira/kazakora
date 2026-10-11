<?php

namespace App\Notifications;

use App\Modules\Checkout\Models\Order;
use Illuminate\Notifications\Notification;

/**
 * Espelha InvoiceIssuanceFailedNotification — mesma ideia, agora pro caso
 * de CheckShipmentLabelJob esgotar as 4h de retry sem o canal liberar a
 * etiqueta.
 */
class LabelUnavailableNotification extends Notification
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
            'message' => "Etiqueta do pedido #{$this->order->id} não ficou disponível: {$this->reason}",
        ];
    }
}
