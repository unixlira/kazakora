<?php

namespace App\Jobs;

use App\Models\User;
use App\Modules\Marketplace\Models\ChannelWebhookLog;
use App\Notifications\WebhookImportFailedNotification;
use App\Services\Shopee\Webhooks\WebhookHandler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Fica na fila "default" de propósito — o mesmo bug real já encontrado com
 * o Mercado Livre (cron do homolog roda `queue:work` sem `--queue=`, então
 * uma fila nomeada nunca é drenada) se aplicaria aqui igual. Ver
 * config/mercadolivre.php.
 */
class ProcessShopeeWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly array $payload,
        public readonly int $webhookLogId,
    ) {
    }

    public function handle(WebhookHandler $handler): void
    {
        $handler->handle($this->payload, $this->webhookLogId);
    }

    /**
     * Chamado quando as $tries esgotam de verdade — ver
     * WebhookImportFailedNotification pro porquê disso existir agora
     * (achado real 2026-08-08: uma venda real sumiu sem avisar ninguém).
     */
    public function failed(?Throwable $exception): void
    {
        $orderSn = $this->payload['data']['ordersn']
            ?? $this->payload['data']['order_sn']
            ?? $this->payload['ordersn']
            ?? $this->payload['order_sn']
            ?? null;

        $admins = User::query()->where('role', User::ROLE_ADMIN)->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new WebhookImportFailedNotification('shopee', $orderSn, $exception?->getMessage() ?? 'Erro desconhecido'));
        }
    }
}
