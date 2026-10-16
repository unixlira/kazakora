<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Importação de relatório de pagamentos da Amazon (2026-10-12): no sino do
 * admin, bolinha verde quando importou e vermelha quando deu erro.
 */
class AmazonRelatorioNotification extends Notification
{
    public function __construct(private readonly bool $sucesso, private readonly string $detalhe) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'message' => $this->sucesso ? 'Relatório de pagamentos da Amazon importado' : 'Erro importação relatório de pagamentos Amazon',
            'body' => $this->detalhe,
            'nivel' => $this->sucesso ? 'sucesso' : 'erro',
        ];
    }
}
