<?php

namespace App\Jobs;

use App\Services\Shopee\Webhooks\WebhookHandler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

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
}
