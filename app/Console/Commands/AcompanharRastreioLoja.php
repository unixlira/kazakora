<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Jobs\SendOrderDeliveredWhatsAppJob;
use App\Modules\Checkout\Jobs\SendOrderShippedWhatsAppJob;
use App\Modules\Checkout\Models\Order;
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
 * WhatsApp "Seu pedido saiu para entrega"; entregue vira "concluído" e
 * manda o WhatsApp "Seu pedido foi entregue" (SendOrderDeliveredWhatsAppJob).
 */
class AcompanharRastreioLoja extends Command
{
    protected $signature = 'loja:acompanhar-rastreio {--dias=45 : pedidos criados nos últimos N dias} {--simular : só mostra, não grava nem envia}';

    protected $description = 'Atualiza pedidos do site pelo rastreio dos Correios e avisa envio e entrega no WhatsApp';

    /** Postagem ou entrega mais antiga que isso não gera aviso (primeira rodada, atrasos). */
    private const AVISO_ATE_HORAS = 72;

    public function handle(CorreiosRastroService $rastro, OrderImportService $pedidos): int
    {
        $simular = (bool) $this->option('simular');
        $contagem = ['consultados' => 0, 'enviados' => 0, 'entregues' => 0, 'falhas' => 0];

        Order::query()
            ->with('latestCorreiosPrePostagem')
            ->where('origin', Order::ORIGIN_STORE)
            ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_SHIPPED])
            ->where('created_at', '>=', now()->subDays((int) $this->option('dias')))
            ->whereHas('latestCorreiosPrePostagem', fn ($q) => $q->whereNotNull('codigo_objeto'))
            ->chunkById(50, function ($lote) use ($rastro, $pedidos, $simular, &$contagem) {
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

                    if ($r['entregue']) {
                        $pedidos->syncStatus($order, Order::STATUS_COMPLETED, 'correios:entregue');
                        $contagem['entregues']++;

                        $recente = ! $r['entregue_em'] || $r['entregue_em']->gt(now()->subHours(self::AVISO_ATE_HORAS));
                        if ($order->whatsapp_entregue_em === null && $recente) {
                            SendOrderDeliveredWhatsAppJob::dispatch($order->id, $r['entregue_em']?->toIso8601String(), $r['recebedor']);
                        }

                        continue;
                    }

                    if ($order->status === Order::STATUS_PAID) {
                        $pedidos->syncStatus($order, Order::STATUS_SHIPPED, 'correios:postado');
                        $contagem['enviados']++;
                    }

                    $recente = ! $r['postado_em'] || $r['postado_em']->gt(now()->subHours(self::AVISO_ATE_HORAS));
                    if ($order->whatsapp_enviado_em === null && $recente) {
                        SendOrderShippedWhatsAppJob::dispatch($order->id, $r['previsao']?->toDateString());
                    }
                }
            });

        $this->info(json_encode($contagem));

        return self::SUCCESS;
    }
}
