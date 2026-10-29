<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Jobs\EnviarEmailPedidoEnviadoJob;
use App\Modules\Checkout\Jobs\SendOrderDeliveredWhatsAppJob;
use App\Modules\Checkout\Jobs\SendOrderShippedWhatsAppJob;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Marketplace\Support\OrderImportService;
use App\Services\Correios\CorreiosRastroService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pedido do site acompanhado pelo rastreio dos Correios (2026-10-17). Nem os
 * Correios nem o KoraSync avisam o despacho (sem webhook), e até hoje pedido
 * da loja ficava "pago" para sempre. A cada 30 minutos consulta o objeto da
 * pré-postagem: primeiro evento físico (postagem) vira "enviado" e manda o
 * WhatsApp "Seu pedido saiu para entrega" e o e-mail "Seu pedido foi
 * enviado" (EnviarEmailPedidoEnviadoJob); entregue vira "concluído" e
 * manda o WhatsApp "Seu pedido foi entregue" (SendOrderDeliveredWhatsAppJob).
 *
 * Entrega no histórico do pedido (2026-10-29): o evento de entrega inteiro
 * (data, quem recebeu, documento, unidade, coordenadas) vira a etapa
 * "Entregue" na linha do tempo. Pedido já concluído sem essa etapa também é
 * consultado, só para gravá-la: sem mudar status nem mandar aviso.
 */
class AcompanharRastreioLoja extends Command
{
    protected $signature = 'loja:acompanhar-rastreio {--dias=45 : pedidos criados nos últimos N dias} {--simular : só mostra, não grava nem envia}';

    protected $description = 'Atualiza pedidos do site pelo rastreio dos Correios e avisa envio e entrega no WhatsApp';

    /** Postagem ou entrega mais antiga que isso não gera aviso (primeira rodada, atrasos). */
    private const AVISO_ATE_HORAS = 72;

    public function handle(CorreiosRastroService $rastro, OrderImportService $pedidos, OrderFulfillmentTimeline $timeline): int
    {
        $simular = (bool) $this->option('simular');
        $contagem = ['consultados' => 0, 'enviados' => 0, 'entregues' => 0, 'falhas' => 0];

        Order::query()
            ->with('latestCorreiosPrePostagem')
            ->where('origin', Order::ORIGIN_STORE)
            ->where(fn ($q) => $q->whereIn('status', [Order::STATUS_PAID, Order::STATUS_SHIPPED])
                ->orWhere(fn ($q) => $q->where('status', Order::STATUS_COMPLETED)
                    ->whereDoesntHave('fulfillmentEvents', fn ($e) => $e->where('step', OrderFulfillmentEvent::STEP_DELIVERED))))
            ->where('created_at', '>=', now()->subDays((int) $this->option('dias')))
            ->whereHas('latestCorreiosPrePostagem', fn ($q) => $q->whereNotNull('codigo_objeto'))
            ->chunkById(50, function ($lote) use ($rastro, $pedidos, $timeline, $simular, &$contagem) {
                foreach ($lote as $order) {
                    $codigo = $order->latestCorreiosPrePostagem->codigo_objeto;
                    $contagem['consultados']++;

                    try {
                        $r = $rastro->consultar($codigo);
                    } catch (Throwable $e) {
                        $contagem['falhas']++;
                        Log::warning('loja.rastreio_falhou', ['order_id' => $order->id, 'error' => $e->getMessage()]);

                        continue;
                    }

                    $this->line("#{$order->id} {$order->status}: ".($r['ultimo'] ?? 'sem evento'));

                    if ($simular || ! $r['postado']) {
                        continue;
                    }

                    if ($order->status === Order::STATUS_COMPLETED) {
                        if ($r['entregue']) {
                            $this->registrarEntrega($timeline, $order, $codigo, $r);
                        }

                        continue;
                    }

                    if ($r['entregue']) {
                        $this->registrarEntrega($timeline, $order, $codigo, $r);
                        $pedidos->syncStatus($order, Order::STATUS_COMPLETED, 'correios:entregue');
                        $contagem['entregues']++;

                        $recente = ! $r['entregue_em'] || $r['entregue_em']->gt(now()->subHours(self::AVISO_ATE_HORAS));
                        if ($order->whatsapp_entregue_em === null && $recente) {
                            SendOrderDeliveredWhatsAppJob::dispatch($order->id, $r['entregue_em']?->toIso8601String(), $r['recebedor']);
                        }

                        continue;
                    }

                    $recente = ! $r['postado_em'] || $r['postado_em']->gt(now()->subHours(self::AVISO_ATE_HORAS));

                    if ($order->status === Order::STATUS_PAID) {
                        $pedidos->syncStatus($order, Order::STATUS_SHIPPED, 'correios:postado');
                        $contagem['enviados']++;

                        // E-mail "Seu pedido foi enviado" (2026-10-19), uma vez, na virada para enviado.
                        if ($recente) {
                            EnviarEmailPedidoEnviadoJob::dispatch($order->id, $r['previsao']?->toDateString());
                        }
                    }

                    if ($order->whatsapp_enviado_em === null && $recente) {
                        SendOrderShippedWhatsAppJob::dispatch($order->id, $r['previsao']?->toDateString());
                    }
                }
            });

        $this->info(json_encode($contagem));

        return self::SUCCESS;
    }

    /**
     * Etapa "Entregue" com o evento do SRO inteiro no context (a tela do
     * pedido mostra cada campo e o bruto). Nada é mascarado nem descartado.
     */
    private function registrarEntrega(OrderFulfillmentTimeline $timeline, Order $order, string $codigo, array $r): void
    {
        $quando = $r['entregue_em']?->format('d/m/Y H:i') ?? 'data não informada';
        $quem = $r['recebedor'] ?? 'não informado pelos Correios';

        $timeline->record(
            $order,
            OrderFulfillmentEvent::STEP_DELIVERED,
            OrderFulfillmentEvent::STATUS_SUCCESS,
            "Entregue pelos Correios em {$quando}. Quem recebeu: {$quem}.",
            ['fonte' => 'correios', 'codigo_objeto' => $codigo, 'evento' => $r['entrega']],
        );
    }
}
