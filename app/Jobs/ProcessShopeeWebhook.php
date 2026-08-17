<?php

namespace App\Jobs;

use App\Models\User;
use App\Modules\Marketplace\Jobs\PokeShopeeLabelChecksJob;
use App\Modules\Marketplace\Models\ChannelWebhookLog;
use App\Notifications\WebhookImportFailedNotification;
use App\Services\Shopee\Webhooks\WebhookHandler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
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
 *
 * ShouldBeUnique por order_sn desde 2026-08-17 — BUG REAL confirmado ao
 * vivo (pedido 260817JCXFKP1R, nunca importado): a Shopee reentregou o
 * mesmo webhook 2x em 11s, as 2 execuções concorrentes de
 * WebhookHandler::handle() → OrderImportService::import() →
 * ShopeeDriver::autoImportProduct() passaram pelo check-então-create do
 * produto (SKU "SHOPEE-58265922084") antes de qualquer uma comitar, e a
 * segunda estourou a constraint única — o import inteiro deu rollback e o
 * pedido nunca chegou a existir no banco (silencioso, só apareceu como 2
 * ChannelWebhookLog "failed"). O SKU em si também ficou mais resistente a
 * corrida (ver ShopeeDriver::autoImportProduct()), mas a trava aqui fecha
 * o problema na origem: a 2ª entrega enquanto a 1ª ainda processa
 * simplesmente não redespacha, sem erro.
 */
class ProcessShopeeWebhook implements ShouldQueue, ShouldBeUnique
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

    public function uniqueId(): string
    {
        $orderSn = $this->payload['data']['ordersn']
            ?? $this->payload['data']['order_sn']
            ?? $this->payload['ordersn']
            ?? $this->payload['order_sn']
            ?? null;

        return (string) ($orderSn ?? $this->webhookLogId);
    }

    /** Cobre tries+backoff (10+30+60=100s) com folga pro import real rodar. */
    public function uniqueFor(): int
    {
        return 180;
    }

    public function handle(WebhookHandler $handler): void
    {
        $handler->handle($this->payload, $this->webhookLogId);

        // "Empurrão" pra qualquer etiqueta Shopee ainda pendente reagir na
        // hora em vez de esperar o próximo minuto do cron — ver
        // PokeShopeeLabelChecksJob (mesmo padrão já usado pro Mercado Livre).
        PokeShopeeLabelChecksJob::dispatch();
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
