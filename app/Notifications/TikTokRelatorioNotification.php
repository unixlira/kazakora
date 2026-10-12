<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Importação de arquivo financeiro do TikTok (2026-10-11): no sino do admin,
 * bolinha verde quando importou e vermelha quando deu erro.
 * Contagem do dia no título (pedido 2026-10-12): "Erro: 1/10" = 1 falha em
 * 10 envios do dia.
 */
class TikTokRelatorioNotification extends Notification
{
    /** @param  array{falhas: int, total: int}|null  $contagem */
    public function __construct(private readonly bool $sucesso, private readonly string $detalhe, private readonly ?array $contagem = null)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $c = $this->contagem;
        $fracao = $c ? "{$c['falhas']}/{$c['total']}" : null;

        return [
            'message' => match (true) {
                ! $this->sucesso && $fracao !== null => "Erro: {$fracao} importação arquivo financeiro TikTok",
                ! $this->sucesso => 'Erro importação arquivo financeiro TikTok',
                $c && $c['falhas'] > 0 => "Arquivo financeiro do TikTok importado (erros hoje: {$fracao})",
                default => 'Arquivo financeiro do TikTok importado',
            },
            'body' => $this->detalhe,
            'nivel' => $this->sucesso ? 'sucesso' : 'erro',
            'falhas_dia' => $c['falhas'] ?? null,
            'total_dia' => $c['total'] ?? null,
        ];
    }
}
