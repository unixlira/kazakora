<?php

namespace App\Notifications;

use App\Modules\Suporte\Models\SolicitacaoCancelamento;
use Illuminate\Notifications\Notification;

/**
 * Cliente pediu para cancelar uma compra já postada (Lira, 2026-11-17).
 * Nada foi cancelado: o pedido espera a equipe em Atendimento > Cancelamentos.
 */
class CancelamentoSolicitadoAposPostagemNotification extends Notification
{
    public function __construct(private readonly SolicitacaoCancelamento $solicitacao)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $order = $this->solicitacao->order;
        $rastreio = $this->solicitacao->rastreio ? " Rastreio: {$this->solicitacao->rastreio}." : '';

        return [
            'order_id' => $order?->id,
            'message' => "Cliente pediu o cancelamento do pedido #{$order?->id}, que já foi postado",
            'body' => "Motivo: {$this->solicitacao->motivo}.{$rastreio} O pedido NÃO foi cancelado: responda o cliente no chamado.",
            'link' => '/admin/cancelamentos',
            'acao' => 'Ver cancelamentos',
            'icone' => 'fa-box-open',
        ];
    }
}
