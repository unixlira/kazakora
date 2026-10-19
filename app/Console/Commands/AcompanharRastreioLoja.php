<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Checkout\Jobs\EnviarEmailPedidoEnviadoJob;
use App\Modules\Checkout\Jobs\SendOrderDeliveredWhatsAppJob;
use App\Modules\Checkout\Jobs\SendOrderShippedWhatsAppJob;
use App\Modules\Checkout\Jobs\SendOrderTrackingTextWhatsAppJob;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Models\Rastreio;
use App\Modules\Marketplace\Support\OrderImportService;
use App\Modules\Marketplace\Support\RegistroDeRastreio;
use App\Notifications\EntregaForaDoDestinoNotification;
use App\Services\Correios\CorreiosRastroService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
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
 * WhatsApp "Seu pedido saiu para entrega" e o e-mail "Seu pedido foi
 * enviado" (EnviarEmailPedidoEnviadoJob); entregue vira "concluído" e
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
 *
 * Desde 2026-10-19: consulta em lote (até 50 objetos por chamada, GET
 * /v1/objetos), grava o histórico de eventos (RegistroDeRastreio, tela
 * Logística › Rastreio), para de consultar objeto entregue ou devolvido e
 * também guarda o histórico dos objetos dos Correios de outros canais
 * (Amazon, Mercado Livre), só para consulta: status e aviso desses pedidos
 * continuam com o canal. Os avisos de WhatsApp (e o e-mail de enviado) ao cliente só saem com
 * CORREIOS_RASTREIO_WHATSAPP=true (services.correios.rastreio_whatsapp);
 * desligado, o resto (histórico e status) roda igual. Docs em
 * docs/correios-rastro.md.
 */
class AcompanharRastreioLoja extends Command
{
    protected $signature = 'loja:acompanhar-rastreio {--dias=45 : pedidos criados nos últimos N dias} {--simular : só mostra, não grava nem envia} {--pedido= : só este pedido, sem esperar o intervalo} {--sem-outros-canais : não guarda o histórico de Amazon/ML}';

    protected $description = 'Atualiza pedidos do site pelo rastreio dos Correios e avisa envio e entrega no WhatsApp';

    /** Postagem ou entrega mais antiga que isso não gera aviso (primeira rodada, atrasos). */
    private const AVISO_ATE_HORAS = 72;

    /** Pacote fora da rota de entrega é reconsultado só depois disso. */
    private const INTERVALO_FORA_DA_ENTREGA_MINUTOS = 30;

    public function handle(CorreiosRastroService $rastro, OrderImportService $pedidos, RegistroDeRastreio $registro): int
    {
        $simular = (bool) $this->option('simular');
        $pedido = $this->option('pedido');
        $whatsapp = (bool) config('services.correios.rastreio_whatsapp');
        $contagem = ['consultados' => 0, 'aguardando_intervalo' => 0, 'encerrados' => 0, 'enviados' => 0, 'em_entrega' => 0, 'retirada' => 0, 'entregues' => 0, 'fora_do_destino' => 0, 'falhas' => 0, 'eventos_novos' => 0, 'whatsapp_desligado' => 0];

        Order::query()
            ->with('latestCorreiosPrePostagem')
            ->where('origin', Order::ORIGIN_STORE)
            ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_SHIPPED])
            ->where('created_at', '>=', now()->subDays((int) $this->option('dias')))
            ->whereHas('latestCorreiosPrePostagem', fn ($q) => $q->whereNotNull('codigo_objeto'))
            ->when($pedido, fn ($q) => $q->whereKey((int) $pedido))
            ->chunkById(CorreiosRastroService::LOTE_MAXIMO, function ($lote) use ($rastro, $pedidos, $registro, $simular, $pedido, $whatsapp, &$contagem) {
                $codigos = $lote->mapWithKeys(fn (Order $o) => [$o->id => strtoupper(trim((string) $o->latestCorreiosPrePostagem->codigo_objeto))]);
                $encerrados = Rastreio::query()->whereIn('codigo', $codigos->values())->whereNotNull('encerrado_em')->pluck('codigo')->all();

                $fila = [];
                foreach ($lote as $order) {
                    $chave = "loja:rastreio:proxima:{$order->id}";
                    if (! $pedido && in_array($codigos[$order->id], $encerrados, true)) {
                        // Entregue ou devolvido: não consulta mais.
                        $contagem['encerrados']++;

                        continue;
                    }
                    if (! $pedido && Cache::has($chave)) {
                        $contagem['aguardando_intervalo']++;

                        continue;
                    }
                    $fila[] = $order;
                }
                if ($fila === []) {
                    return;
                }

                // Uma chamada para o lote todo (até 50 objetos).
                try {
                    $objetos = $rastro->objetos(array_map(fn (Order $o) => $codigos[$o->id], $fila));
                } catch (Throwable $e) {
                    $contagem['falhas'] += count($fila);
                    Log::warning('loja.rastreio_falhou', ['orders' => array_map(fn (Order $o) => $o->id, $fila), 'error' => $e->getMessage()]);
                    if (! $simular) {
                        foreach ($fila as $order) {
                            $registro->registrarFalha($codigos[$order->id], $order->id, $e->getMessage());
                        }
                    }

                    return;
                }

                foreach ($fila as $order) {
                    $codigo = $codigos[$order->id];
                    $chave = "loja:rastreio:proxima:{$order->id}";
                    $contagem['consultados']++;
                    $objeto = $objetos[$codigo] ?? ['mensagem' => 'Objeto não veio na resposta dos Correios'];
                    $r = CorreiosRastroService::interpretar($objeto);
                    $foraDoDestino = $r['entregue'] && self::foraDoDestino($r['entregue_cidade'], $order->shipping_city);

                    if (! $simular) {
                        $contagem['eventos_novos'] += $registro->registrar($codigo, $order->id, $objeto, $foraDoDestino);
                    }

                    $this->line("#{$order->id} {$order->status}: ".($r['ultimo'] ?? 'sem evento'));

                    // Saiu para entrega: volta na próxima rodada (5 min).
                    if (! $simular && ! $r['em_entrega']) {
                        Cache::put($chave, true, now()->addMinutes(self::INTERVALO_FORA_DA_ENTREGA_MINUTOS)->subSeconds(30));
                    }

                    if ($simular || ! $r['postado']) {
                        continue;
                    }

                    if ($foraDoDestino) {
                        $contagem['fora_do_destino']++;
                        $this->avisarForaDoDestino($order, $r['entregue_cidade']);

                        continue;
                    }

                    if ($r['entregue']) {
                        if ($order->entregue_em === null) {
                            $order->forceFill(['entregue_em' => $r['entregue_em'] ?? now()]);
                        }
                        $pedidos->syncStatus($order, Order::STATUS_COMPLETED, 'correios:entregue');
                        $contagem['entregues']++;

                        if ($order->whatsapp_entregue_em === null && self::recente($r['entregue_em']) && $this->whatsappLigado($whatsapp, $order, 'entregue', $contagem)) {
                            filled(config('services.whatsapp.templates.pedido_entregue'))
                                ? SendOrderDeliveredWhatsAppJob::dispatch($order->id, $r['entregue_em']?->toIso8601String(), $r['recebedor'])
                                : SendOrderTrackingTextWhatsAppJob::dispatchSync($order->id, SendOrderTrackingTextWhatsAppJob::ENTREGUE, ['quando' => $r['entregue_em']?->toIso8601String()]);
                        }

                        continue;
                    }

                    if ($order->status === Order::STATUS_PAID) {
                        $pedidos->syncStatus($order, Order::STATUS_SHIPPED, 'correios:postado');
                        $contagem['enviados']++;

                        // E-mail "Seu pedido foi enviado" (2026-10-19), uma vez, na virada para enviado.
                        // Mesma chave dos avisos de WhatsApp: desligada, o cliente não recebe nada.
                        if ($whatsapp && self::recente($r['postado_em'])) {
                            EnviarEmailPedidoEnviadoJob::dispatch($order->id, $r['previsao']?->toDateString());
                        }
                    }

                    // Texto sai na hora, sem fila: as etapas chegam em ordem e o
                    // homolog não tem worker (fila parada desde 07/10).
                    if ($order->whatsapp_enviado_em === null && self::recente($r['postado_em']) && $this->whatsappLigado($whatsapp, $order, 'postado', $contagem)) {
                        filled(config('services.whatsapp.templates.pedido_enviado'))
                            ? SendOrderShippedWhatsAppJob::dispatch($order->id, $r['previsao']?->toDateString())
                            : SendOrderTrackingTextWhatsAppJob::dispatchSync($order->id, SendOrderTrackingTextWhatsAppJob::POSTADO, ['previsao' => $r['previsao']?->toDateString()]);
                    }

                    if ($r['em_entrega']) {
                        $contagem['em_entrega']++;
                        if ($order->whatsapp_saiu_entrega_em === null && self::recente($r['saiu_em']) && $this->whatsappLigado($whatsapp, $order, 'saiu_para_entrega', $contagem)) {
                            SendOrderTrackingTextWhatsAppJob::dispatchSync($order->id, SendOrderTrackingTextWhatsAppJob::SAIU_PARA_ENTREGA, ['quando' => $r['saiu_em']?->toIso8601String()]);
                        }
                    }

                    if ($r['aguardando_retirada']) {
                        $contagem['retirada']++;
                        if ($order->whatsapp_retirada_em === null && self::recente($r['retirada_em']) && $this->whatsappLigado($whatsapp, $order, 'aguardando_retirada', $contagem)) {
                            SendOrderTrackingTextWhatsAppJob::dispatchSync($order->id, SendOrderTrackingTextWhatsAppJob::AGUARDANDO_RETIRADA, ['local' => $r['retirada_local']]);
                        }
                    }
                }
            });

        $this->info(json_encode($contagem));

        if (! $pedido && ! $this->option('sem-outros-canais')) {
            $this->info(json_encode(['outros_canais' => $this->historicoOutrosCanais($rastro, $registro, $simular)]));
        }

        return self::SUCCESS;
    }

    /**
     * Aviso ao cliente só com CORREIOS_RASTREIO_WHATSAPP=true. Desligado, a
     * etapa fica sem aviso (o campo whatsapp_*_em continua vazio) e, se ligar
     * depois, só etapa das últimas AVISO_ATE_HORAS horas é avisada.
     */
    private function whatsappLigado(bool $ligado, Order $order, string $etapa, array &$contagem): bool
    {
        if (! $ligado) {
            $contagem['whatsapp_desligado']++;
            Log::info('loja.rastreio_whatsapp_desligado', ['order_id' => $order->id, 'etapa' => $etapa]);
        }

        return $ligado;
    }

    /**
     * Histórico dos objetos dos Correios de pedidos de outros canais (Amazon,
     * Mercado Livre): só grava os eventos para a tela de Rastreio, sem mudar
     * status nem avisar ninguém (o canal cuida disso). Cada objeto é
     * reconsultado no máximo a cada INTERVALO_FORA_DA_ENTREGA_MINUTOS e para
     * quando entrega ou volta para a loja.
     *
     * @return array{consultados: int, eventos_novos: int, falhas: int}
     */
    private function historicoOutrosCanais(CorreiosRastroService $rastro, RegistroDeRastreio $registro, bool $simular): array
    {
        $contagem = ['consultados' => 0, 'eventos_novos' => 0, 'falhas' => 0];

        $candidatos = CorreiosPrePostagem::query()
            ->whereNotNull('codigo_objeto')
            ->whereNotNull('order_id')
            ->where('status', '!=', CorreiosPrePostagem::STATUS_CANCELADA)
            ->where('created_at', '>=', now()->subDays((int) $this->option('dias')))
            ->whereHas('order', fn ($q) => $q->where('origin', '!=', Order::ORIGIN_STORE)->where('status', '!=', Order::STATUS_CANCELLED))
            ->orderBy('id')
            ->get(['id', 'order_id', 'codigo_objeto'])
            ->mapWithKeys(fn ($p) => [strtoupper(trim($p->codigo_objeto)) => $p->order_id])
            ->filter(fn ($orderId, $codigo) => CorreiosRastroService::codigoValido($codigo));

        $parados = Rastreio::query()
            ->whereIn('codigo', $candidatos->keys())
            ->where(fn ($q) => $q->whereNotNull('encerrado_em')
                ->orWhere('consultado_em', '>', now()->subMinutes(self::INTERVALO_FORA_DA_ENTREGA_MINUTOS)->addSeconds(30)))
            ->pluck('codigo')
            ->all();
        $candidatos = $candidatos->except($parados);

        foreach ($candidatos->chunk(CorreiosRastroService::LOTE_MAXIMO) as $lote) {
            try {
                $objetos = $rastro->objetos($lote->keys()->all());
            } catch (Throwable $e) {
                $contagem['falhas'] += $lote->count();
                Log::warning('rastreio.outros_canais_falhou', ['error' => $e->getMessage()]);

                continue;
            }

            foreach ($lote as $codigo => $orderId) {
                $contagem['consultados']++;
                if (! $simular) {
                    $contagem['eventos_novos'] += $registro->registrar($codigo, $orderId, $objetos[$codigo] ?? ['mensagem' => 'Objeto não veio na resposta dos Correios']);
                }
            }
        }

        return $contagem;
    }

    /** Evento sem data ou dos últimos AVISO_ATE_HORAS: avisa o cliente. */
    private static function recente(?Carbon $quando): bool
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
