<?php

namespace App\Jobs;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelWebhookLog;
use App\Modules\Marketplace\Support\OrderImportService;
use App\Notifications\WebhookImportFailedNotification;
use App\Services\Bling\BlingInvoiceImporter;
use App\Services\Bling\BlingOrderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Import de verdade do pedido que chegou pelo webhook do Bling — fora do
 * ciclo da requisição porque o Bling exige resposta em 5 segundos (ver
 * BlingWebhookController). O canal (TikTok Shop ou Amazon) sai da
 * `data.loja.id` do próprio payload — ver BlingOrderService::
 * channelForLojaId().
 *
 * Fila "default" de propósito: o cron do homolog roda `queue:work` sem
 * `--queue=`, então fila nomeada nunca é drenada (mesmo motivo documentado
 * em ProcessShopeeWebhook).
 *
 * ShouldBeUnique por numeroLoja: `created` e `updated` do mesmo pedido
 * podem chegar juntos (e fora de ordem — o Bling não garante ordem), e
 * duas execuções concorrentes do mesmo import competiriam pelo mesmo
 * INSERT, o mesmo tipo de corrida que já derrubou import da Shopee antes.
 */
class ProcessBlingOrderWebhook implements ShouldQueue, ShouldBeUnique
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
        return $this->externalOrderId() ?? (string) $this->webhookLogId;
    }

    /** Cobre tries+backoff (10+30+60=100s) com folga pro import real rodar. */
    public function uniqueFor(): int
    {
        return 180;
    }

    public function handle(OrderImportService $importer, BlingOrderService $blingOrders): void
    {
        $log = ChannelWebhookLog::find($this->webhookLogId);
        $externalOrderId = $this->externalOrderId();
        $event = (string) ($this->payload['event'] ?? '');
        // Fallback TikTok: job enfileirado antes da Amazon entrar só podia
        // ser do TikTok (o controller descartava qualquer outra loja).
        $channel = $blingOrders->channelForLojaId($this->payload['data']['loja']['id'] ?? null) ?? Order::ORIGIN_TIKTOK_SHOP;

        if ($externalOrderId === null) {
            // Pedido de venda sem numeroLoja não é venda de marketplace
            // (lançamento manual dentro do Bling, por exemplo) — não tem o
            // que importar como pedido do canal.
            $log?->update(['status' => ChannelWebhookLog::STATUS_IGNORED, 'error_message' => 'Evento sem numeroLoja — não é pedido de marketplace.']);

            return;
        }

        // Exclusão DEFINITIVA no Bling (mudar a situação pra "excluído"
        // chega como `updated`, não como `deleted`). O pedido não existe
        // mais lá pra reconsultar, então nada de import — e cancelar por
        // conta própria seria arriscado demais: pedido apagado no ERP não
        // quer dizer venda cancelada no canal. Registra alto pra alguém
        // olhar.
        if ($event === 'order.deleted') {
            Log::warning('bling.webhook.order_deleted', [
                'channel' => $channel,
                'external_order_id' => $externalOrderId,
                'bling_order_id' => $this->payload['data']['id'] ?? null,
            ]);

            $log?->update(['status' => ChannelWebhookLog::STATUS_IGNORED, 'error_message' => 'Pedido excluído definitivamente no Bling — conferir manualmente.']);

            return;
        }

        // O payload já diz qual é o id interno do pedido no Bling. Guardar
        // essa correspondência evita que o import tenha que varrer 60 dias
        // de pedidos da loja só pra achar esse numeroLoja (ver
        // BlingOrderService::findByOrderNumber()) — economia que importa
        // porque o teto do Bling é 3 req/s pra conta INTEIRA, disputados
        // com emissão de nota e busca de etiqueta.
        if ($blingId = $this->payload['data']['id'] ?? null) {
            $blingOrders->rememberOrderId($externalOrderId, (int) $blingId);
        }

        $order = $importer->import($channel, $externalOrderId);

        // A nota do TikTok é emitida PELO BLING (ver
        // services.bling.invoice_issuer_channels): traz ela pra cá, com XML
        // e DANFE, senão o pedido ficaria sem registro nenhum de NF-e do
        // nosso lado. Pedido ainda sem nota lá volta null e a próxima
        // varredura tenta de novo — a emissão no Bling é assíncrona.
        // A Amazon só entra aqui se também for delegada ao Bling: hoje a
        // nota dela é NOSSA, e importar a do Bling criaria nota em dobro.
        $bringsBlingInvoice = $channel === Order::ORIGIN_TIKTOK_SHOP
            || in_array($channel, (array) config('services.bling.invoice_issuer_channels', []), true);

        if ($order && $bringsBlingInvoice) {
            app(BlingInvoiceImporter::class)->syncForOrder($order);
        }

        $log?->update(['status' => ChannelWebhookLog::STATUS_PROCESSED]);
    }

    public function failed(?Throwable $exception): void
    {
        ChannelWebhookLog::where('id', $this->webhookLogId)->update([
            'status' => ChannelWebhookLog::STATUS_FAILED,
            'error_message' => mb_substr((string) $exception?->getMessage(), 0, 1000),
        ]);

        $admins = User::query()->where('role', User::ROLE_ADMIN)->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new WebhookImportFailedNotification(
                app(BlingOrderService::class)->channelForLojaId($this->payload['data']['loja']['id'] ?? null) ?? Order::ORIGIN_TIKTOK_SHOP,
                $this->externalOrderId(),
                (string) $exception?->getMessage(),
            ));
        }
    }

    /** numeroLoja é o número do pedido no canal (TikTok/Amazon) — o external_order_id do nosso lado. */
    private function externalOrderId(): ?string
    {
        $numeroLoja = $this->payload['data']['numeroLoja'] ?? null;

        return $numeroLoja !== null && (string) $numeroLoja !== '' ? (string) $numeroLoja : null;
    }
}
