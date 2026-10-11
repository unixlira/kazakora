<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\WebhookImportFailedNotification;
use App\Services\MercadoLivre\Webhooks\WebhookHandler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * ShouldBeUnique por resource desde 2026-08-17 — mesma corrida real
 * encontrada e corrigida no equivalente da Shopee (ver ProcessShopeeWebhook):
 * o canal pode reentregar o mesmo webhook mais de uma vez em segundos, e
 * duas execuções concorrentes do mesmo import (mesmo pedido/shipment) podem
 * passar por um check-então-create de qualquer efeito colateral (produto
 * auto-importado, etc.) antes de qualquer uma commitar, estourando uma
 * constraint única. Trava aqui fecha a corrida na origem — a 2ª entrega
 * enquanto a 1ª ainda está processando/retentando simplesmente não
 * redespacha (silencioso, sem erro), e uma mudança real posterior ainda
 * chega por um webhook seguinte ou pelos pokes/polling já existentes.
 */
class ProcessMercadoLivreWebhook implements ShouldQueue, ShouldBeUnique
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

    public function uniqueId(): string
    {
        return (string) ($this->payload['resource'] ?? $this->webhookLogId);
    }

    /** Cobre tries+backoff (10+30+60=100s) com folga pro import real rodar. */
    public function uniqueFor(): int
    {
        return 180;
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
