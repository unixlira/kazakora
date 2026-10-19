<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * ADS de um canal acima do limite (6% da receita) ou de volta abaixo dele,
 * no sino do admin (2026-10-19). Quem decide quando avisar é o comando
 * financeiro:vigiar-ads; a regra é a do RadarDeAds.
 */
class AdsAcimaDoLimiteNotification extends Notification
{
    public function __construct(
        private readonly string $mensagem,
        private readonly string $detalhe,
        private readonly ?string $link,
        private readonly bool $voltou = false,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'message' => $this->mensagem,
            'body' => $this->detalhe,
            'link' => $this->link,
            'nivel' => $this->voltou ? 'sucesso' : 'erro',
        ];
    }
}
