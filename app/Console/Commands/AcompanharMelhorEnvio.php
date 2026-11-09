<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\MelhorEnvioEnvio;
use App\Modules\Marketplace\Support\AcompanhamentoMelhorEnvio;
use App\Services\MelhorEnvio\MelhorEnvioClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pedido do site com etiqueta do Melhor Envio acompanhado pela API (pedido
 * do Lira em 2026-11-09, árvore #3978: "preciso saber tudo via API para
 * refletir no Kazakora"). Mesmo fluxo do loja:acompanhar-rastreio dos
 * Correios, a cada 30 minutos (me/shipment/tracking, até 100 por chamada):
 *
 *   - rastreio da transportadora vai para o envio e para o ChannelShipment
 *     (a etiqueta sai antes de a transportadora dar o código);
 *   - postado: pedido "enviado", e-mail "Seu pedido foi enviado" e WhatsApp
 *     "saiu para entrega" com a previsão (postagem + prazo da transportadora);
 *   - entregue: pedido "concluído" e WhatsApp "foi entregue";
 *   - cancelado ou vencido sem postar: fica no envio e no log.
 *
 * Aviso de postagem/entrega mais antiga que 72 h não sai (rodada atrasada).
 */
class AcompanharMelhorEnvio extends Command
{
    protected $signature = 'melhorenvio:acompanhar {--dias=45 : envios gerados nos últimos N dias} {--simular : só mostra, não grava nem envia}';

    protected $description = 'Atualiza pedidos do site pelo rastreio do Melhor Envio e avisa envio e entrega no WhatsApp';

    public function handle(MelhorEnvioClient $client, AcompanhamentoMelhorEnvio $acompanhamento): int
    {
        $simular = (bool) $this->option('simular');
        $contagem = ['consultados' => 0, 'enviados' => 0, 'entregues' => 0, 'cancelados' => 0, 'sem_mudanca' => 0, 'falhas' => 0];

        MelhorEnvioEnvio::query()
            ->with('order')
            ->where('status', MelhorEnvioEnvio::STATUS_GERADA)
            ->whereNotNull('me_order_id')
            ->whereNull('entregue_em')
            ->whereNull('cancelada_em')
            ->where('gerada_em', '>=', now()->subDays((int) $this->option('dias')))
            ->whereHas('order', fn ($q) => $q->where('origin', Order::ORIGIN_STORE)->whereIn('status', [Order::STATUS_PAID, Order::STATUS_SHIPPED]))
            ->chunkById(100, function ($lote) use ($client, $acompanhamento, $simular, &$contagem) {
                try {
                    $respostas = $client->post('me/shipment/tracking', ['orders' => $lote->pluck('me_order_id')->values()->all()]);
                } catch (Throwable $e) {
                    $contagem['falhas'] += $lote->count();
                    Log::channel(config('melhorenvio.log_channel'))->warning('melhorenvio.acompanhar_falhou', ['error' => $e->getMessage()]);

                    return;
                }

                foreach ($lote as $envio) {
                    $info = $respostas[$envio->me_order_id] ?? null;
                    if (! is_array($info)) {
                        continue;
                    }
                    $contagem['consultados']++;
                    $this->line("#{$envio->order_id} {$envio->order->status}: ".($info['status'] ?? '?').' '.($info['tracking'] ?? ''));

                    if (! $simular) {
                        $contagem[$acompanhamento->refletir($envio, $info)]++;
                    }
                }
            });

        $this->info(json_encode($contagem));

        return self::SUCCESS;
    }
}
