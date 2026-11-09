<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Checkout\Jobs\EnviarEmailPedidoEnviadoJob;
use App\Modules\Checkout\Jobs\SendOrderDeliveredWhatsAppJob;
use App\Modules\Checkout\Jobs\SendOrderShippedWhatsAppJob;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MelhorEnvioEnvio;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Estado do envio do Melhor Envio refletido no pedido do site (2026-11-09,
 * árvore #3978): rastreio da transportadora, postado (pedido enviado,
 * e-mail e WhatsApp "saiu para entrega"), entregue (concluído e WhatsApp
 * "foi entregue"), cancelado/vencido (log). Chamado pelo webhook do
 * Melhor Envio (MelhorEnvioWebhookController) e, de reserva, pelo
 * melhorenvio:acompanhar a cada 30 minutos. Os jobs de WhatsApp têm trava
 * própria (whatsapp_enviado_em / whatsapp_entregue_em): repetir não duplica.
 */
class AcompanhamentoMelhorEnvio
{
    /** Postagem ou entrega mais antiga que isso não gera aviso (rodada atrasada). */
    private const AVISO_ATE_HORAS = 72;

    public function __construct(private readonly OrderImportService $pedidos) {}

    /**
     * Aplica o estado do Melhor Envio (resposta do me/shipment/tracking ou
     * "data" do webhook) no envio e no pedido. Devolve o que aconteceu:
     * enviados, entregues, cancelados ou sem_mudanca.
     */
    public function refletir(MelhorEnvioEnvio $envio, array $info): string
    {
        $data = fn (?string $v) => filled($v) ? CarbonImmutable::parse($v, 'UTC')->setTimezone(config('app.timezone')) : null;
        $postadoEm = $data($info['posted_at'] ?? null);
        $entregueEm = $data($info['delivered_at'] ?? null);
        $canceladoEm = $data($info['canceled_at'] ?? null) ?? $data($info['expired_at'] ?? null);
        $rastreio = $info['tracking'] ?? null;

        $envio->fill(array_filter([
            'situacao' => $info['status'] ?? null,
            'rastreio' => $rastreio,
            'postada_em' => $postadoEm,
            'entregue_em' => $entregueEm,
            'cancelada_em' => $canceladoEm,
        ], fn ($v) => $v !== null))->save();

        if ($rastreio) {
            ChannelShipment::query()->where('order_id', $envio->order_id)->where('channel', Order::ORIGIN_STORE)
                ->where(fn ($q) => $q->whereNull('tracking_code')->orWhere('tracking_code', '!=', $rastreio))
                ->update(['tracking_code' => $rastreio]);
        }

        $order = $envio->order()->first();
        $pedidos = $this->pedidos;
        $resultado = 'sem_mudanca';

        if ($canceladoEm) {
            Log::channel(config('melhorenvio.log_channel'))->warning('melhorenvio.envio_cancelado', ['order_id' => $order->id, 'situacao' => $info['status'] ?? null]);

            return 'cancelados';
        }

        if ($entregueEm) {
            $pedidos->syncStatus($order, Order::STATUS_COMPLETED, 'melhorenvio:entregue');
            if ($order->whatsapp_entregue_em === null && $entregueEm->gt(now()->subHours(self::AVISO_ATE_HORAS))) {
                SendOrderDeliveredWhatsAppJob::dispatch($order->id, $entregueEm->toIso8601String(), null);
            }

            return 'entregues';
        }

        if (! $postadoEm) {
            return $resultado;
        }

        $recente = $postadoEm->gt(now()->subHours(self::AVISO_ATE_HORAS));
        $previsao = $envio->prazo_dias ? $postadoEm->addWeekdays($envio->prazo_dias)->toDateString() : null;

        if ($order->status === Order::STATUS_PAID) {
            $pedidos->syncStatus($order, Order::STATUS_SHIPPED, 'melhorenvio:postado');
            $resultado = 'enviados';
            if ($recente) {
                EnviarEmailPedidoEnviadoJob::dispatch($order->id, $previsao);
            }
        }

        if ($order->whatsapp_enviado_em === null && $recente) {
            SendOrderShippedWhatsAppJob::dispatch($order->id, $previsao);
        }

        return $resultado;
    }
}
