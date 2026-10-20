<?php

namespace App\Modules\Marketplace\Support;

use App\Models\User;
use App\Modules\Checkout\Jobs\EnviarEmailPedidoEnviadoJob;
use App\Modules\Checkout\Jobs\SendOrderDeliveredWhatsAppJob;
use App\Modules\Checkout\Jobs\SendOrderShippedWhatsAppJob;
use App\Modules\Checkout\Jobs\SendOrderTrackingTextWhatsAppJob;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Notifications\EntregaForaDoDestinoNotification;
use App\Services\Correios\CorreiosRastroService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * O que um objeto dos Correios muda no pedido, num lugar só (2026-10-19):
 * a consulta agendada (loja:acompanhar-rastreio) e o webhook dos Correios
 * (POST /api/correios/webhook) passam por aqui.
 *
 * - Pedido do site: postado vira "enviado", entregue vira "concluído"
 *   (Entregue) e o cliente é avisado uma vez por etapa (só com
 *   CORREIOS_RASTREIO_WHATSAPP=true).
 * - Qualquer pedido: a entrega vai para a linha do tempo do pedido com data,
 *   hora, unidade, cidade, descrição e quem recebeu (quando os Correios
 *   informam). Status de pedido de outro canal continua com o canal.
 * - Entrega numa cidade diferente da do pedido é devolução ao remetente (os
 *   Correios marcam como BDE/01): não conclui, não avisa o cliente e chama
 *   os admins uma vez.
 */
class AtualizacaoPeloRastreio
{
    /** Postagem ou entrega mais antiga que isso não gera aviso (primeira rodada, atrasos). */
    public const AVISO_ATE_HORAS = 72;

    public function __construct(
        private readonly OrderImportService $pedidos,
        private readonly OrderFulfillmentTimeline $timeline,
    ) {}

    /**
     * Aplica o objeto (já gravado pelo RegistroDeRastreio) num pedido do site
     * em "pago" ou "enviado". Soma o que aconteceu em $contagem.
     *
     * @param  array<string, int>  $contagem
     */
    public function aplicarNaLoja(Order $order, string $codigo, array $objeto, bool $whatsapp, array &$contagem, string $origem = 'consulta'): void
    {
        $r = CorreiosRastroService::interpretar($objeto);
        if (! $r['postado']) {
            return;
        }

        if ($r['entregue'] && self::foraDoDestino($r['entregue_cidade'], $order->shipping_city)) {
            $this->soma($contagem, 'fora_do_destino');
            $this->registrarEntrega($order, $codigo, $objeto, $origem);
            $this->avisarForaDoDestino($order, $r['entregue_cidade']);

            return;
        }

        if ($r['entregue']) {
            if ($order->entregue_em === null) {
                $order->forceFill(['entregue_em' => $r['entregue_em'] ?? now()]);
            }
            $this->pedidos->syncStatus($order, Order::STATUS_COMPLETED, 'correios:entregue');
            $this->registrarEntrega($order, $codigo, $objeto, $origem);
            $this->soma($contagem, 'entregues');

            if ($order->whatsapp_entregue_em === null && self::recente($r['entregue_em']) && $this->whatsappLigado($whatsapp, $order, 'entregue', $contagem)) {
                filled(config('services.whatsapp.templates.pedido_entregue'))
                    ? SendOrderDeliveredWhatsAppJob::dispatch($order->id, $r['entregue_em']?->toIso8601String(), $r['recebedor'])
                    : SendOrderTrackingTextWhatsAppJob::dispatchSync($order->id, SendOrderTrackingTextWhatsAppJob::ENTREGUE, ['quando' => $r['entregue_em']?->toIso8601String()]);
            }

            return;
        }

        if ($order->status === Order::STATUS_PAID) {
            $this->pedidos->syncStatus($order, Order::STATUS_SHIPPED, 'correios:postado');
            $this->soma($contagem, 'enviados');

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
            $this->soma($contagem, 'em_entrega');
            if ($order->whatsapp_saiu_entrega_em === null && self::recente($r['saiu_em']) && $this->whatsappLigado($whatsapp, $order, 'saiu_para_entrega', $contagem)) {
                SendOrderTrackingTextWhatsAppJob::dispatchSync($order->id, SendOrderTrackingTextWhatsAppJob::SAIU_PARA_ENTREGA, ['quando' => $r['saiu_em']?->toIso8601String()]);
            }
        }

        if ($r['aguardando_retirada']) {
            $this->soma($contagem, 'retirada');
            if ($order->whatsapp_retirada_em === null && self::recente($r['retirada_em']) && $this->whatsappLigado($whatsapp, $order, 'aguardando_retirada', $contagem)) {
                SendOrderTrackingTextWhatsAppJob::dispatchSync($order->id, SendOrderTrackingTextWhatsAppJob::AGUARDANDO_RETIRADA, ['local' => $r['retirada_local']]);
            }
        }
    }

    /**
     * Grava a entrega na linha do tempo do pedido (etapa "Entregue"), com o
     * que os Correios informaram. Sem evento de entrega, não faz nada.
     * Repetir (webhook reentregue, consulta depois do webhook) não cria
     * linha nova: a mensagem não muda com a origem.
     */
    public function registrarEntrega(Order $order, string $codigo, array $objeto, string $origem = 'consulta'): ?OrderFulfillmentEvent
    {
        $entrega = self::dadosDaEntrega($objeto);
        if ($entrega === null) {
            return null;
        }

        $devolvido = self::foraDoDestino($entrega['cidade'], $order->shipping_city);
        $quando = $entrega['entregue_em']?->format('d/m/Y \à\s H:i');
        $local = trim(implode(', ', array_filter([
            $entrega['unidade'],
            trim(implode('/', array_filter([$entrega['cidade'], $entrega['uf']]))),
        ])));

        $partes = array_filter([
            ($devolvido ? 'Correios marcaram entregue' : 'Entregue').($quando ? " em {$quando}" : ''),
            $entrega['descricao'],
            $local !== '' ? $local : null,
            'Quem recebeu: '.($entrega['recebedor'] ?? 'não informado pelos Correios')
                .($entrega['recebedor_documento'] ? " (doc. {$entrega['recebedor_documento']})" : '')
                .($entrega['recebedor_comentario'] ? " ({$entrega['recebedor_comentario']})" : ''),
            "Objeto {$codigo}",
            $devolvido ? "Cidade diferente da do pedido ({$order->shipping_city}): devolução ao remetente, pedido não concluído" : null,
        ]);

        $status = $devolvido ? OrderFulfillmentEvent::STATUS_FAILED : OrderFulfillmentEvent::STATUS_SUCCESS;
        $mensagem = Str::limit(implode(' · ', $partes), 1000);

        // Já gravada (consulta e webhook avisam a mesma entrega): fica a primeira.
        $jaGravada = OrderFulfillmentEvent::query()
            ->where('order_id', $order->id)
            ->where('step', OrderFulfillmentEvent::STEP_DELIVERED)
            ->latest('id')
            ->first();
        if ($jaGravada && $jaGravada->status === $status && $jaGravada->message === $mensagem) {
            return $jaGravada;
        }

        return $this->timeline->record(
            $order,
            OrderFulfillmentEvent::STEP_DELIVERED,
            $status,
            $mensagem,
            [
                'codigo_objeto' => $codigo,
                'origem' => $origem,
                'evento' => $entrega['codigo'].'/'.$entrega['tipo'],
                'entregue_em' => $entrega['entregue_em']?->toIso8601String(),
                'descricao' => $entrega['descricao'],
                'detalhe' => $entrega['detalhe'],
                'unidade' => $entrega['unidade'],
                'cidade' => $entrega['cidade'],
                'uf' => $entrega['uf'],
                'recebedor' => $entrega['recebedor'],
                'recebedor_documento' => $entrega['recebedor_documento'],
                'recebedor_comentario' => $entrega['recebedor_comentario'],
                'devolvido' => $devolvido,
            ],
        );
    }

    /**
     * Evento de entrega (BDE/BDI/BDR tipo 01) do objeto, já limpo. Do
     * recebedor ficam o nome, o comentário (porteiro, vizinho...) e o
     * documento mascarado; celular, e-mail e coordenadas não.
     *
     * @return array{codigo: string, tipo: string, entregue_em: ?Carbon, descricao: ?string, detalhe: ?string, unidade: ?string, cidade: ?string, uf: ?string, recebedor: ?string, recebedor_documento: ?string, recebedor_comentario: ?string}|null
     */
    public static function dadosDaEntrega(array $objeto): ?array
    {
        $e = collect($objeto['eventos'] ?? [])->first(fn ($e) => in_array(strtoupper((string) ($e['codigo'] ?? '')), ['BDE', 'BDI', 'BDR'], true) && ($e['tipo'] ?? '') === '01');
        if ($e === null) {
            return null;
        }

        $texto = fn (mixed $v, int $max) => filled($v) && is_scalar($v) ? mb_substr(trim((string) $v), 0, $max) : null;
        $nome = $texto($e['recebedor']['nome'] ?? null, 72);
        $doc = preg_replace('/\D/', '', (string) ($e['recebedor']['documento'] ?? ''));

        return [
            'codigo' => strtoupper((string) $e['codigo']),
            'tipo' => (string) $e['tipo'],
            'entregue_em' => filled($e['dtHrCriado'] ?? null) ? Carbon::parse($e['dtHrCriado'], 'America/Sao_Paulo') : null,
            'descricao' => $texto($e['descricao'] ?? null, 200),
            'detalhe' => $texto($e['detalhe'] ?? null, 200),
            'unidade' => $texto($e['unidade']['nome'] ?? ($e['unidade']['tipo'] ?? null), 120),
            'cidade' => $texto($e['unidade']['endereco']['cidade'] ?? null, 80),
            'uf' => $texto($e['unidade']['endereco']['uf'] ?? null, 2),
            'recebedor' => $nome ? Str::title(mb_strtolower($nome)) : null,
            'recebedor_documento' => strlen($doc) >= 4 ? '***'.substr($doc, -4) : null,
            'recebedor_comentario' => $texto($e['recebedor']['comentario'] ?? null, 50),
        ];
    }

    /** Evento sem data ou dos últimos AVISO_ATE_HORAS: avisa o cliente. */
    public static function recente(?Carbon $quando): bool
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

    /**
     * Aviso ao cliente só com CORREIOS_RASTREIO_WHATSAPP=true. Desligado, a
     * etapa fica sem aviso (o campo whatsapp_*_em continua vazio) e, se ligar
     * depois, só etapa das últimas AVISO_ATE_HORAS horas é avisada.
     */
    private function whatsappLigado(bool $ligado, Order $order, string $etapa, array &$contagem): bool
    {
        if (! $ligado) {
            $this->soma($contagem, 'whatsapp_desligado');
            Log::info('loja.rastreio_whatsapp_desligado', ['order_id' => $order->id, 'etapa' => $etapa]);
        }

        return $ligado;
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

    private function soma(array &$contagem, string $chave): void
    {
        $contagem[$chave] = ($contagem[$chave] ?? 0) + 1;
    }
}
