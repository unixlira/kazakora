<?php

namespace App\Notifications;

use App\Modules\Marketplace\Models\MarketplaceReturn;
use Illuminate\Notifications\Notification;

/**
 * Pendência nova numa devolução/reclamação (ReturnsSyncService::avisar()):
 * caso aberto, prazo acabando ou vencido, produto entregue sem conferência,
 * encerrada sem o produto voltar. Uma por pendência, uma vez só.
 */
class MarketplaceReturnAlertNotification extends Notification
{
    private const CANAIS = [
        'mercado_livre' => 'Mercado Livre',
        'shopee' => 'Shopee',
        'tiktok_shop' => 'TikTok Shop',
        'amazon' => 'Amazon',
    ];

    public function __construct(
        private readonly MarketplaceReturn $devolucao,
        private readonly string $titulo,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $canal = self::CANAIS[$this->devolucao->channel] ?? $this->devolucao->channel;
        $pedido = $this->devolucao->order_id ? "pedido #{$this->devolucao->order_id}" : "pedido {$this->devolucao->external_order_id}";

        return [
            'order_id' => $this->devolucao->order_id,
            'message' => "Devolução {$canal}: {$this->titulo}",
            'body' => trim($pedido.($this->devolucao->reason_label ? " — {$this->devolucao->reason_label}" : '')),
            'link' => '/admin/devolucoes?caso='.$this->devolucao->id,
        ];
    }
}
