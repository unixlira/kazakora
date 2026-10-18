<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Jobs\SendOrderDeliveredWhatsAppJob;
use App\Modules\Checkout\Jobs\SendOrderShippedWhatsAppJob;
use App\Modules\Checkout\Jobs\SendOrderTrackingTextWhatsAppJob;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Support\OrderImportService;
use App\Models\User;
use App\Notifications\EntregaForaDoDestinoNotification;
use App\Services\Correios\CorreiosRastroService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pedido do site acompanhado pelo rastreio dos Correios (2026-10-17). Nem os
 * Correios nem o KoraSync avisam o despacho (sem webhook), e até hoje pedido
 * da loja ficava "pago" para sempre. A cada 30 minutos consulta o objeto da
 * pré-postagem: primeiro evento físico (postagem) vira "enviado" e manda o
 * WhatsApp "Seu pedido saiu para entrega"; entregue vira "concluído" e
 * manda o WhatsApp "Seu pedido foi entregue" (SendOrderDeliveredWhatsAppJob).
 * Entrega numa cidade diferente da do pedido é devolução ao remetente (os
 * Correios marcam como BDE/01): não conclui, não avisa o cliente e chama
 * os admins uma vez.
 *
 * De 5 em 5 minutos desde 2026-10-18 (pedido do Lira): pacote que saiu para
 * entrega é consultado em toda rodada; os demais, de 30 em 30 minutos. Cada
 * etapa (postado, saiu para entrega, aguardando retirada, entregue) avisa
 * uma vez: template aprovado na Meta quando houver, senão texto da Manuela
 * (SendOrderTrackingTextWhatsAppJob).
 */
class AcompanharRastreioLoja extends Command
{
    protected $signature = 'loja:acompanhar-rastreio {--dias=45 : pedidos criados nos últimos N dias} {--simular : só mostra, não grava nem envia} {--pedido= : só este pedido, sem esperar o intervalo}';

    protected $description = 'Atualiza pedidos do site pelo rastreio dos Correios e avisa envio e entrega no WhatsApp';

    /** Postagem ou entrega mais antiga que isso não gera aviso (primeira rodada, atrasos). */
    private const AVISO_ATE_HORAS = 72;

    /** Pacote fora da rota de entrega é reconsultado só depois disso. */
    private const INTERVALO_FORA_DA_ENTREGA_MINUTOS = 30;

    public function handle(CorreiosRastroService $rastro, OrderImportService $pedidos): int
    {
        $simular = (bool) $this->option('simular');
        $pedido = $this->option('pedido');
        $contagem = ['consultados' => 0, 'aguardando_intervalo' => 0, 'enviados' => 0, 'em_entrega' => 0, 'retirada' => 0, 'entregues' => 0, 'fora_do_destino' => 0, 'falhas' => 0];

        Order::query()
            ->with('latestCorreiosPrePostagem')
            ->where('origin', Order::ORIGIN_STORE)
            ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_SHIPPED])
            ->where('created_at', '>=', now()->subDays((int) $this->option('dias')))
            ->whereHas('latestCorreiosPrePostagem', fn ($q) => $q->whereNotNull('codigo_objeto'))
            ->when($pedido, fn ($q) => $q->whereKey((int) $pedido))
            ->chunkById(50, function ($lote) use ($rastro, $pedidos, $simular, $pedido, &$contagem) {
                foreach ($lote as $order) {
                    $codigo = $order->latestCorreiosPrePostagem->codigo_objeto;
                    $chave = "loja:rastreio:proxima:{$order->id}";
                    if (! $pedido && Cache::has($chave)) {
                        $contagem['aguardando_intervalo']++;

                        continue;
                    }
                    $contagem['consultados']++;

                    try {
                        $r = $rastro->consultar($codigo);
                    } catch (Throwable $e) {
                        $contagem['falhas']++;
                        Log::warning('loja.rastreio_falhou', ['order_id' => $order->id, 'error' => $e->getMessage()]);

                        continue;
                    }

                    $this->line("#{$order->id} {$order->status}: ".($r['ultimo'] ?? 'sem evento'));

                    // Saiu para entrega: volta na próxima rodada (5 min).
                    if (! $simular && ! $r['em_entrega']) {
                        Cache::put($chave, true, now()->addMinutes(self::INTERVALO_FORA_DA_ENTREGA_MINUTOS)->subSeconds(30));
                    }

                    if ($simular || ! $r['postado']) {
                        continue;
                    }

                    if ($r['entregue'] && self::foraDoDestino($r['entregue_cidade'], $order->shipping_city)) {
                        $contagem['fora_do_destino']++;
                        $this->avisarForaDoDestino($order, $r['entregue_cidade']);

                        continue;
                    }

                    if ($r['entregue']) {
                        $pedidos->syncStatus($order, Order::STATUS_COMPLETED, 'correios:entregue');
                        $contagem['entregues']++;

                        if ($order->whatsapp_entregue_em === null && self::recente($r['entregue_em'])) {
                            filled(config('services.whatsapp.templates.pedido_entregue'))
                                ? SendOrderDeliveredWhatsAppJob::dispatch($order->id, $r['entregue_em']?->toIso8601String(), $r['recebedor'])
                                : SendOrderTrackingTextWhatsAppJob::dispatchSync($order->id, SendOrderTrackingTextWhatsAppJob::ENTREGUE, ['quando' => $r['entregue_em']?->toIso8601String()]);
                        }

                        continue;
                    }

                    if ($order->status === Order::STATUS_PAID) {
                        $pedidos->syncStatus($order, Order::STATUS_SHIPPED, 'correios:postado');
                        $contagem['enviados']++;
                    }

                    // Texto sai na hora, sem fila: as etapas chegam em ordem e o
                    // homolog não tem worker (fila parada desde 07/10).
                    if ($order->whatsapp_enviado_em === null && self::recente($r['postado_em'])) {
                        filled(config('services.whatsapp.templates.pedido_enviado'))
                            ? SendOrderShippedWhatsAppJob::dispatch($order->id, $r['previsao']?->toDateString())
                            : SendOrderTrackingTextWhatsAppJob::dispatchSync($order->id, SendOrderTrackingTextWhatsAppJob::POSTADO, ['previsao' => $r['previsao']?->toDateString()]);
                    }

                    if ($r['em_entrega']) {
                        $contagem['em_entrega']++;
                        if ($order->whatsapp_saiu_entrega_em === null && self::recente($r['saiu_em'])) {
                            SendOrderTrackingTextWhatsAppJob::dispatchSync($order->id, SendOrderTrackingTextWhatsAppJob::SAIU_PARA_ENTREGA, ['quando' => $r['saiu_em']?->toIso8601String()]);
                        }
                    }

                    if ($r['aguardando_retirada']) {
                        $contagem['retirada']++;
                        if ($order->whatsapp_retirada_em === null && self::recente($r['retirada_em'])) {
                            SendOrderTrackingTextWhatsAppJob::dispatchSync($order->id, SendOrderTrackingTextWhatsAppJob::AGUARDANDO_RETIRADA, ['local' => $r['retirada_local']]);
                        }
                    }
                }
            });

        $this->info(json_encode($contagem));

        return self::SUCCESS;
    }

    /** Evento sem data ou dos últimos AVISO_ATE_HORAS: avisa o cliente. */
    private static function recente(?\Illuminate\Support\Carbon $quando): bool
    {
        return ! $quando || $quando->gt(now()->subHours(self::AVISO_ATE_HORAS));
    }

    /** Cidade da entrega conhecida e diferente da cidade do pedido. */
    public static function foraDoDestino(?string $entregueEm, ?string $destino): bool
    {
        if (! filled($entregueEm) || ! filled($destino)) {
            return false;
        }

        $normaliza = fn (string $c) => (string) Str::of($c)->ascii()->upper()->replaceMatches('/[^A-Z]/', '');

        return $normaliza($entregueEm) !== $normaliza($destino);
    }

    private function avisarForaDoDestino(Order $order, string $cidade): void
    {
        Log::warning('loja.rastreio_entrega_fora_do_destino', ['order_id' => $order->id, 'entregue_em' => $cidade, 'destino' => $order->shipping_city]);

        if (! Cache::add("loja:rastreio:fora-do-destino:{$order->id}", true, now()->addDays(60))) {
            return;
        }

        $admins = User::query()->where('role', User::ROLE_ADMIN)->get();
        if ($admins->isNotEmpty()) {
            Notification::send($admins, new EntregaForaDoDestinoNotification($order, $cidade));
        }
    }
}
