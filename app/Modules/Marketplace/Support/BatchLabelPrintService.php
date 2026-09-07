<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Jobs\CheckShipmentLabelJob;
use App\Modules\Marketplace\Jobs\ConfirmChannelShippingJob;
use App\Modules\Marketplace\Models\PrintJob;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "Gerar etiquetas em lote" — pedido do usuário em 2026-09-07, junto com a
 * inversão do fluxo do galpão.
 *
 * ANTES: separava um pedido de cada vez e cada clique soltava a etiqueta
 * dele. DEPOIS (agora): imprime-se o lote inteiro de etiquetas disponíveis
 * de uma vez, e só então o operador vai dando baixa na separação com os
 * papéis já na mão. A baixa não imprime mais nada — ver
 * DashboardAgentController::separateOrder().
 *
 * O que entra no lote: pedido ainda PAGO, ainda NÃO separado (packed_at
 * nulo), de Shopee ou Mercado Livre, com a etiqueta JÁ baixada do canal.
 *
 * O que NÃO entra, e por quê:
 * - TikTok Shop e Shein: etiqueta é do painel do canal, nunca da nossa
 *   impressora (ver LabelFetchService::CANAIS_SEM_IMPRESSAO_NOSSA).
 * - Pedido cujo canal ainda não liberou a etiqueta: não há o que imprimir.
 *   Ele aparece no resumo como "sem etiqueta" pra não sumir calado.
 * - Pedido cuja etiqueta JÁ saiu na impressora: não sai de novo (a regra do
 *   duplicado de 2026-09-07). Aparece no resumo como "já impressa", com a
 *   data — a 2ª via continua no botão de reimprimir.
 *
 * Nenhuma consulta ao canal acontece aqui: o lote só manda pra impressora
 * o que já está baixado e guardado. É o que faz o botão responder na hora
 * mesmo com 40 pedidos na fila.
 */
class BatchLabelPrintService
{
    private const CANAIS = [
        Order::ORIGIN_MERCADO_LIVRE,
        Order::ORIGIN_SHOPEE,
    ];

    public function __construct(private readonly LabelFetchService $labels) {}

    /**
     * @return array{
     *     enfileiradas: list<array{order_id:int, canal:string}>,
     *     ja_impressas: list<array{order_id:int, canal:string, impressa_em:?string}>,
     *     sem_etiqueta: list<array{order_id:int, canal:string}>,
     *     total_candidatos: int
     * }
     */
    public function run(bool $seco = false): array
    {
        $orders = Order::query()
            ->nonPurchaseReturn()
            ->where('status', Order::STATUS_PAID)
            ->whereNull('packed_at')
            ->whereIn('origin', self::CANAIS)
            ->with('channelShipment')
            ->orderBy('id')
            ->get();

        $enfileiradas = [];
        $jaImpressas = [];
        $semEtiqueta = [];

        foreach ($orders as $order) {
            $shipment = $order->channelShipment;

            if (! $shipment || ! $shipment->label_path) {
                $semEtiqueta[] = ['order_id' => $order->id, 'canal' => $order->origin];

                if (! $seco) {
                    $this->cutucar($order, $shipment);
                }

                continue;
            }

            // setRelation pra não fazer o serviço reconsultar o pedido que
            // esta query já trouxe.
            $shipment->setRelation('order', $order);

            // O modo seco existe pra conferir o lote antes de gastar papel
            // (foi o que rodou primeiro no dia da mudança). Ele NÃO pode
            // chamar queuePrintInBatch(): esse método imprime.
            $vaiImprimir = $seco
                ? $this->impressaEm($order) === null
                : $this->labels->queuePrintInBatch($shipment);

            if ($vaiImprimir) {
                $enfileiradas[] = ['order_id' => $order->id, 'canal' => $order->origin];

                continue;
            }

            $jaImpressas[] = [
                'order_id' => $order->id,
                'canal' => $order->origin,
                'impressa_em' => $this->impressaEm($order),
            ];
        }

        Log::info($seco ? 'marketplace.batch_label_print.seco' : 'marketplace.batch_label_print', [
            'enfileiradas' => count($enfileiradas),
            'ja_impressas' => count($jaImpressas),
            'sem_etiqueta' => count($semEtiqueta),
        ]);

        return [
            'enfileiradas' => $enfileiradas,
            'ja_impressas' => $jaImpressas,
            'sem_etiqueta' => $semEtiqueta,
            'total_candidatos' => $orders->count(),
        ];
    }

    /**
     * Pedido que ainda não tem etiqueta baixada: cutuca o canal pra ela
     * chegar, e sai. Herdado do nudgeLabel() que morava na separação — o
     * lote é o lugar certo pra isso agora, porque é aqui que alguém olha
     * pro dia inteiro de uma vez.
     *
     * Só dispara job, nunca imprime: o caminho automático (attempt() ->
     * queuePrint()) continua exigindo packed_at, então uma etiqueta que
     * chegue por causa deste empurrão fica GUARDADA esperando o próximo
     * lote. É de propósito — máquina não gasta papel.
     *
     * Melhor-esforço: falhar aqui não pode derrubar o lote inteiro, que já
     * mandou etiqueta de verdade pra impressora.
     */
    private function cutucar(Order $order, ?object $shipment): void
    {
        try {
            if ($shipment) {
                CheckShipmentLabelJob::dispatch($shipment->id);

                return;
            }

            ConfirmChannelShippingJob::dispatch($order->id);
        } catch (Throwable $exception) {
            Log::warning('marketplace.batch_label_print.nudge_failed', [
                'order_id' => $order->id,
                'canal' => $order->origin,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /** O mesmo lote, sem mandar nada pra impressora. */
    public function preview(): array
    {
        return $this->run(seco: true);
    }

    private function impressaEm(Order $order): ?string
    {
        $job = PrintJob::query()
            ->where('order_id', $order->id)
            ->where('is_thank_you', false)
            ->latest('id')
            ->first();

        if ($job?->status !== PrintJob::STATUS_PRINTED) {
            return null;
        }

        return $job->printed_at?->format('d/m/Y H:i');
    }
}
