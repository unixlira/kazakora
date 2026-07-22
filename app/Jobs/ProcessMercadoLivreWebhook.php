<?php

namespace App\Jobs;

use App\Services\MercadoLivre\Webhooks\WebhookHandler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessMercadoLivreWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public readonly array $payload)
    {
        $this->onQueue(config('mercadolivre.queue'));
    }

    public function handle(WebhookHandler $handler): void
    {
        $handler->handle($this->payload);
    }
}
