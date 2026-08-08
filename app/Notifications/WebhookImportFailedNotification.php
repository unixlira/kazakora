<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Achado real 2026-08-08: um pedido Shopee sumiu por completo (venda real,
 * confirmada no painel do próprio canal) porque ProcessShopeeWebhook
 * esgotou as 3 tentativas e ninguém nunca ficou sabendo — o job simplesmente
 * morreu em failed_jobs, sem log visível em lugar nenhum do admin. Toda
 * outra etapa do pipeline (nota fiscal, envio, etiqueta) já tem esse tipo
 * de aviso porque já existe uma Order pra pendurar nele; a importação do
 * pedido em si é o único ponto sem rede de segurança nenhuma, porque é
 * justamente o ponto ONDE a Order ainda não existe. Esta notificação fecha
 * esse buraco — dispara quando ProcessShopeeWebhook/ProcessMercadoLivreWebhook
 * esgota as tentativas de verdade (falha permanente, não uma retentativa
 * normal em andamento).
 */
class WebhookImportFailedNotification extends Notification
{
    public function __construct(
        private readonly string $channel,
        private readonly ?string $externalOrderId,
        private readonly string $reason,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $order = $this->externalOrderId ? " (pedido {$this->externalOrderId})" : '';

        return [
            'channel' => $this->channel,
            'external_order_id' => $this->externalOrderId,
            'reason' => $this->reason,
            'message' => "Falha ao importar venda da {$this->channel}{$order}: {$this->reason}. Verifique em Integrações > Webhooks.",
        ];
    }
}
