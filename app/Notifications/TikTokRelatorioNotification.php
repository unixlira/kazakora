<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Importação de arquivo financeiro do TikTok (2026-10-11): no sino do admin,
 * bolinha verde quando importou e vermelha quando deu erro.
 */
class TikTokRelatorioNotification extends Notification
{
    public function __construct(private readonly bool $sucesso, private readonly string $detalhe)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'message' => $this->sucesso ? 'Arquivo financeiro do TikTok importado' : 'Erro importação arquivo financeiro TikTok',
            'body' => $this->detalhe,
            'nivel' => $this->sucesso ? 'sucesso' : 'erro',
        ];
    }
}
