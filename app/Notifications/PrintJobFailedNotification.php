<?php

namespace App\Notifications;

use App\Modules\Marketplace\Models\PrintJob;
use Illuminate\Notifications\Notification;

class PrintJobFailedNotification extends Notification
{
    public function __construct(private readonly PrintJob $printJob, private readonly string $reason)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'order_id' => $this->printJob->order_id,
            'print_job_id' => $this->printJob->id,
            'reason' => $this->reason,
            'message' => "Etiqueta do pedido #{$this->printJob->order_id} ficou pronta mas não foi impressa: {$this->reason}. Imprima manualmente.",
        ];
    }
}
