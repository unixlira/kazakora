<?php

namespace App\Modules\Marketplace\Jobs;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Support\AmazonDbaShipping;
use App\Notifications\LabelUnavailableNotification;
use Carbon\CarbonImmutable;
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
 * Pedido Amazon DBA que a API não deixou agendar (24/11/2026, pedido #4474:
 * "The operation is not supported for the given order"). O Lira foi avisado
 * para agendar no Seller Central; este job confere a cada 30 min, até o
 * prazo de envio, se o pacote apareceu. Quando aparece, dispara o
 * ConfirmChannelShippingJob, que grava o rastreio e põe o
 * CheckShipmentLabelJob para baixar a etiqueta (feed do Easy Ship).
 *
 * ShouldBeUnique por pedido: vários avisos do mesmo pedido não empilham.
 */
class AguardarAgendamentoDbaJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const INTERVALO_MINUTOS = 30;

    /** Teto de segurança; quem decide quando parar é retryUntil(). */
    public int $tries = 1000;

    public function __construct(
        public readonly int $orderId,
        public readonly CarbonImmutable $prazo,
    ) {
    }

    public function uniqueId(): string
    {
        return (string) $this->orderId;
    }

    public function uniqueFor(): int
    {
        return max(3600, $this->prazo->getTimestamp() - CarbonImmutable::now()->getTimestamp() + 3600);
    }

    public function retryUntil(): CarbonImmutable
    {
        // Mesmo com o prazo vencido ainda vale achar o pacote: confere por
        // mais algumas horas antes de desistir.
        return $this->prazo->addHours(6);
    }

    public function handle(AmazonDbaShipping $dba): void
    {
        $order = Order::find($this->orderId);

        if (! $order || $order->status !== Order::STATUS_PAID || $this->jaAgendado($order)) {
            return;
        }

        try {
            $agendado = $dba->pacoteAgendado($order);
        } catch (Throwable $exception) {
            Log::channel('amazon')->warning('amazon.dba.aguardar_agendamento_falhou', ['order_id' => $order->id, 'erro' => $exception->getMessage()]);
            $agendado = false;
        }

        if ($agendado) {
            ConfirmChannelShippingJob::dispatch($order->id);

            return;
        }

        $this->release(self::INTERVALO_MINUTOS * 60);
    }

    public function failed(?Throwable $exception): void
    {
        $order = Order::find($this->orderId);

        if (! $order || $this->jaAgendado($order)) {
            return;
        }

        $admins = User::query()->where('role', User::ROLE_ADMIN)->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new LabelUnavailableNotification($order, "o pedido Amazon {$order->external_order_id} passou do prazo de envio sem pacote agendado no DBA. Agende no Seller Central (Ponto de Envio)."));
        }
    }

    private function jaAgendado(Order $order): bool
    {
        $envio = ChannelShipment::query()->where('order_id', $order->id)->where('channel', $order->origin)->first();

        return $envio !== null
            && filled($envio->tracking_code)
            && in_array($envio->status, [ChannelShipment::STATUS_CONFIRMED, ChannelShipment::STATUS_LABEL_READY, ChannelShipment::STATUS_LABEL_DOWNLOADED], true);
    }
}
