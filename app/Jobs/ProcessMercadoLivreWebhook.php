<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\WebhookImportFailedNotification;
use App\Services\MercadoLivre\Webhooks\WebhookHandler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use Throwable;

class ProcessMercadoLivreWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public readonly array $payload, public readonly int $webhookLogId)
    {
        $this->onQueue(config('mercadolivre.queue'));
    }

    public function handle(WebhookHandler $handler): void
    {
        $handler->handle($this->payload, $this->webhookLogId);
    }

    /**
     * Chamado quando as $tries esgotam de verdade — ver
     * WebhookImportFailedNotification pro porquê disso existir agora
     * (achado real 2026-08-08: uma venda Shopee sumiu sem avisar ninguém;
     * o mesmo buraco existia aqui, mesmo padrão de job).
     */
    public function failed(?Throwable $exception): void
    {
        $admins = User::query()->where('role', User::ROLE_ADMIN)->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new WebhookImportFailedNotification('mercado_livre', $this->payload['resource'] ?? null, $exception?->getMessage() ?? 'Erro desconhecido'));
        }
    }
}
