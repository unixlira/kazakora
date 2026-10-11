<?php

namespace App\Notifications;

use App\Modules\Admin\Models\PromotionalNotificationCampaign;
use Illuminate\Notifications\Notification;

/**
 * Notificação de promoção/cupom pra clientes do site — cadastrada e
 * disparada pelo admin em /admin/notificacoes-promocionais (pedido
 * explícito 2026-08-06). Só canal database: aparece na sineta da loja
 * (AppLayout.vue), não é e-mail nem push — mesma decisão já usada em
 * OrderStatusUpdated, sem infra nova.
 */
class PromotionalNotification extends Notification
{
    public function __construct(private readonly PromotionalNotificationCampaign $campaign) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'campaign_id' => $this->campaign->id,
            'message' => $this->campaign->title,
            'body' => $this->campaign->message,
            'link' => $this->campaign->link,
        ];
    }
}
