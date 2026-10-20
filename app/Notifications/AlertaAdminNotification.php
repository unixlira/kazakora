<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Alerta do painel (AlertasAdmin) no sino de notificações do admin
 * (2026-10-17): o Lira pediu notificação no topo, não faixa no meio das
 * telas. Vai uma vez quando o alerta abre; repetição do mesmo problema só
 * soma no alerta (Sistema › Alertas). Some do sino quando o alerta resolve.
 */
class AlertaAdminNotification extends Notification
{
    public function __construct(
        private readonly int $alertaId,
        private readonly string $titulo,
        private readonly string $mensagem,
        private readonly ?string $link,
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
            'nivel' => 'erro',
            'alerta_id' => $this->alertaId,
        ];
    }
}
