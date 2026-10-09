<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** Aviso fiscal no sininho do admin (duplicidade de número, UFESP sem valor...). */
class FiscalAlertNotification extends Notification
{
    public function __construct(
        private readonly string $message,
        private readonly ?string $url = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['message' => $this->message, 'url' => $this->url];
    }
}
