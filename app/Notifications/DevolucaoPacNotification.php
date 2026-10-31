<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Sino do admin na Devolução PAC (Lira 2026-10-31: "quando chegar aqui, eu
 * quero ser notificado pelo sistema para validar se realmente funcionou a
 * nota de devolução"). Ver AcompanharDevolucaoPac.
 */
class DevolucaoPacNotification extends Notification
{
    /** @param  string  $nivel  "sucesso", "erro" ou "info" (cor da bolinha no sino) */
    public function __construct(
        private readonly string $titulo,
        private readonly string $mensagem,
        private readonly string $link,
        private readonly string $nivel = 'info',
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'message' => $this->titulo,
            'body' => $this->mensagem,
            'link' => $this->link,
            'nivel' => $this->nivel,
        ];
    }
}
