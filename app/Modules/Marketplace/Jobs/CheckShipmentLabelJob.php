<?php

namespace App\Modules\Marketplace\Jobs;

use App\Models\User;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Marketplace\Exceptions\LabelNotReadyException;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Support\LabelFetchService;
use App\Notifications\LabelUnavailableNotification;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Substitui o polling por tempo (marketplace:poll-labels a cada 5 min,
 * ainda existe como fallback manual mas não roda mais agendado — ver
 * routes/console.php) por um retry orientado a evento: disparado assim que
 * o frete é confirmado (ChannelShippingService::confirm()) e de novo a
 * cada webhook do Mercado Livre que chega enquanto o envio ainda não tem
 * etiqueta (MercadoLivreController::webhook() → PokeMercadoLivreLabelChecksJob).
 * Pedido explícito do usuário 2026-08-05 ("cron nem precisa ter").
 *
 * ShouldBeUnique por shipment_id garante que múltiplos gatilhos (vários
 * webhooks chegando em sequência) não empilhem cadeias de retry duplicadas
 * pro mesmo envio — o lock fica ativo enquanto o job está "em andamento"
 * (inclusive esperando o backoff entre tentativas) e só solta quando
 * termina de vez (sucesso ou falha definitiva via failed()), que é
 * exatamente o comportamento padrão do Laravel pra ShouldBeUnique.
 *
 * Cada shipment é seu próprio job independente — um pedido travado nas
 * tentativas não atrasa nem bloqueia nenhum outro.
 */
class CheckShipmentLabelJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const RETRY_WINDOW_HOURS = 4;

    private const RETRY_INTERVAL_SECONDS = 5;

    // Teto de segurança — quem realmente decide quando parar é retryUntil().
    public int $tries = 5000;

    public CarbonImmutable $deadline;

    public function __construct(public readonly int $shipmentId, ?CarbonImmutable $deadline = null)
    {
        $this->deadline = $deadline ?? CarbonImmutable::now()->addHours(self::RETRY_WINDOW_HOURS);
    }

    public function uniqueId(): string
    {
        return (string) $this->shipmentId;
    }

    public function uniqueFor(): int
    {
        return self::RETRY_WINDOW_HOURS * 3600 + 300;
    }

    public function retryUntil(): CarbonImmutable
    {
        return $this->deadline;
    }

    public function backoff(): int
    {
        return self::RETRY_INTERVAL_SECONDS;
    }

    public function handle(LabelFetchService $service): void
    {
        $shipment = ChannelShipment::find($this->shipmentId);

        if (! $shipment || $this->alreadyHasLabel($shipment)) {
            return;
        }

        if (! $service->attempt($shipment)) {
            throw new LabelNotReadyException("Etiqueta do envio #{$this->shipmentId} ainda não disponível no canal.");
        }
    }

    /**
     * Chamado pelo Laravel quando retryUntil() vence sem sucesso — falha
     * de vez, registra na linha do tempo do pedido e avisa os admins.
     */
    public function failed(?Throwable $exception): void
    {
        $shipment = ChannelShipment::find($this->shipmentId);

        if (! $shipment || $this->alreadyHasLabel($shipment)) {
            return; // ficou pronta em algum retry concorrente antes do failed() rodar
        }

        $message = 'Etiqueta não ficou disponível no Mercado Livre após '.self::RETRY_WINDOW_HOURS.'h de tentativas.';

        $shipment->update(['status' => ChannelShipment::STATUS_ERROR, 'error_message' => $message]);

        if (! $shipment->order) {
            return;
        }

        app(OrderFulfillmentTimeline::class)->record($shipment->order, OrderFulfillmentEvent::STEP_LABEL_GENERATED, OrderFulfillmentEvent::STATUS_FAILED, $message);

        $admins = User::query()->where('role', User::ROLE_ADMIN)->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new LabelUnavailableNotification($shipment->order, $message));
        }
    }

    private function alreadyHasLabel(ChannelShipment $shipment): bool
    {
        return in_array($shipment->status, [ChannelShipment::STATUS_LABEL_READY, ChannelShipment::STATUS_LABEL_DOWNLOADED], true);
    }
}
