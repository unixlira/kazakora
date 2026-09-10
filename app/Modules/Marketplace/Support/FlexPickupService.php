<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\PrintJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * O motor do KoraFlex (app de celular, repositório à parte): a lista do que
 * a transportadora do Flex tem que levar HOJE, e a bipagem do QR da
 * etiqueta que registra "separado, etiquetado e pronto pra coleta".
 *
 * Nasceu em 2026-09-10 de um problema real com a transportadora do Flex:
 * sem registro de hora do que ficou pronto, discussão sobre pacote não
 * coletado vira palavra contra palavra. Cada bipada agora carimba hora no
 * pedido e na timeline.
 *
 * O QR DA ETIQUETA DO FLEX (decifrado numa etiqueta real, envio
 * 47981054232): o ZPL traz `^BQN,2,7^FDLA,{...}` com um JSON
 *
 *     {"id":"47981054232","sender_id":3283064948,"hash_code":"...","security_digit":"0"}
 *
 * e esse `id` é exatamente o external_shipment_id que já guardamos desde a
 * confirmação do envio — nenhuma consulta ao Mercado Livre é necessária pra
 * casar a bipada com a venda. Ver parseQr() pros formatos aceitos.
 */
class FlexPickupService
{
    /** Estado de cada venda na tela do dia. */
    public const ESTADO_PENDENTE = 'pendente';

    public const ESTADO_PRONTO = 'pronto';

    public const ESTADO_COLETADO = 'coletado';

    public function __construct(private readonly OrderFulfillmentTimeline $timeline) {}

    /**
     * A JANELA DO DIA, a regra que o usuário definiu junto com o app:
     * "se a venda saiu no dia após horário de corte, não deve aparecer,
     * isso seria regra para aparecer no envio do dia seguinte".
     *
     * Então o despacho de um dia D é tudo que foi vendido depois do corte
     * de D-1 e até o corte de D. Com corte às 12:00: uma venda de ontem
     * 15:30 sai hoje; uma de hoje 09:00 sai hoje; uma de hoje 12:01 sai
     * amanhã.
     *
     * A janela NÃO se move ao passar do meio-dia: às 15h de hoje a lista
     * ainda é a de hoje (as caixas continuam esperando a coleta de hoje) —
     * o que passou do corte já está contado no dia seguinte.
     *
     * @return array{de: CarbonImmutable, ate: CarbonImmutable}
     */
    public function janela(?CarbonImmutable $dia = null): array
    {
        $dia ??= CarbonImmutable::now();

        [$hora, $minuto] = $this->corte();

        $ate = $dia->setTime($hora, $minuto);

        return ['de' => $ate->subDay(), 'ate' => $ate];
    }

    /**
     * @return array{hora: string, entregas: list<array<string, mixed>>, atrasados: list<array<string, mixed>>, ...}
     */
    public function dia(?CarbonImmutable $referencia = null): array
    {
        $referencia ??= CarbonImmutable::now();
        $janela = $this->janela($referencia);

        $doDia = $this->flexQuery()
            ->whereBetween('orders.created_at', [$janela['de'], $janela['ate']])
            ->get();

        // ATRASADOS: venda de janela anterior que ninguém bipou e o canal
        // não deu como enviada. Sem esta lista, uma caixa esquecida de
        // ontem some da tela hoje — que é exatamente o tipo de sumiço que
        // gerou a briga com a transportadora.
        $atrasados = $this->flexQuery()
            ->where('orders.created_at', '<', $janela['de'])
            ->where('orders.status', Order::STATUS_PAID)
            ->whereNull('orders.ready_for_pickup_at')
            ->get();

        $entregas = $doDia->map(fn (Order $order) => $this->paraTela($order));
        $pendentesDoDia = $entregas->where('estado', self::ESTADO_PENDENTE)->count();

        return [
            'dia' => $janela['ate']->toDateString(),
            'corte' => $this->corteFormatado(),
            'janela' => [
                'de' => $janela['de']->toDateTimeString(),
                'ate' => $janela['ate']->toDateTimeString(),
            ],
            'total' => $entregas->count(),
            'pendentes' => $pendentesDoDia,
            'prontos' => $entregas->where('estado', self::ESTADO_PRONTO)->count(),
            'coletados' => $entregas->where('estado', self::ESTADO_COLETADO)->count(),

            // FALTAM = tudo que ainda tem que ser bipado, do dia MAIS o
            // atrasado. Pergunta do usuário em 2026-09-10, na primeira vez
            // que ele olhou a tela com uma caixa velha em aberto: "se tem
            // um em aberto atrasado, porque não está no card Faltam?".
            //
            // Estava fora porque o contador espelhava só a janela do dia —
            // e "Faltam" não é uma medida da janela, é a fila de trabalho
            // de quem está no galpão. Um contador que marca 0 com caixa na
            // prateleira é pior que contador nenhum: o atrasado é
            // justamente o que não pode ser esquecido de novo.
            //
            // 'total' continua sendo só o dia (a regra do corte que o
            // usuário definiu) — quem soma é a fila, não o total.
            'atrasados_total' => $atrasados->count(),
            'faltam' => $pendentesDoDia + $atrasados->count(),

            'entregas' => $entregas->values()->all(),
            'atrasados' => $atrasados->map(fn (Order $order) => $this->paraTela($order))->values()->all(),
        ];
    }

    /**
     * Uma bipada. Devolve sempre o mesmo formato — quem decide a cor da
     * tela é o campo `ok` mais o `motivo`.
     *
     * @return array<string, mixed>
     */
    public function bipar(string $qr, ?string $dispositivo = null): array
    {
        $id = $this->parseQr($qr);

        if (! $id) {
            return $this->recusa('qr_ilegivel', 'Não consegui ler essa etiqueta. Aponte pro QR do Flex, não pro código de barras.');
        }

        $shipment = ChannelShipment::query()
            ->where(fn ($query) => $query->where('external_shipment_id', $id)->orWhere('tracking_code', $id))
            ->with('order')
            ->latest('id')
            ->first();

        if (! $shipment || ! $shipment->order) {
            return $this->recusa('nao_encontrada', "Etiqueta {$id} não é de nenhuma venda daqui.");
        }

        $order = $shipment->order;

        // Venda cancelada NUNCA pode ir pra transportadora — este é o
        // aviso que só existe porque a caixa já está fechada na mão de
        // alguém quando a bipada acontece.
        if ($order->status === Order::STATUS_CANCELLED) {
            return $this->recusa('cancelada', 'VENDA CANCELADA — não entregue essa caixa.', $order, $shipment);
        }

        if ($shipment->shipping_method !== ChannelShipment::METHOD_FLEX) {
            return $this->recusa(
                'nao_e_flex',
                'Essa etiqueta não é do Flex — essa venda vai por outro envio, não entra na coleta.',
                $order,
                $shipment,
            );
        }

        // PACK: dois pedidos do mesmo comprador podem dividir UMA etiqueta
        // (achado real no pack 2000014900875351, pedidos #1540/#1541). Uma
        // caixa, uma bipada, os dois pedidos carimbados — senão o segundo
        // fica "pendente" pra sempre numa caixa que já foi embora.
        $irmaos = $this->pedidosDoMesmoEnvio($shipment);

        $jaEstavaPronto = $order->ready_for_pickup_at;

        if (! $jaEstavaPronto) {
            $agora = now();

            DB::transaction(function () use ($irmaos, $agora, $dispositivo) {
                foreach ($irmaos as $irmao) {
                    $irmao->forceFill([
                        // Bipar É a separação concluída: a caixa está
                        // fechada e etiquetada na mão de quem bipou.
                        'packed_at' => $irmao->packed_at ?? $agora,
                        'ready_for_pickup_at' => $agora,
                    ])->save();

                    $this->timeline->record(
                        $irmao,
                        OrderFulfillmentEvent::STEP_READY_FOR_PICKUP,
                        OrderFulfillmentEvent::STATUS_SUCCESS,
                        'Bipado no KoraFlex: etiquetado e pronto pra coleta',
                        ['dispositivo' => $dispositivo],
                    );
                }
            });

            Log::info('koraflex.bipado', [
                'shipment' => $shipment->external_shipment_id,
                'pedidos' => $irmaos->pluck('id')->all(),
                'dispositivo' => $dispositivo,
            ]);
        }

        $order->refresh();

        return [
            'ok' => true,
            'motivo' => $jaEstavaPronto ? 'ja_estava_pronto' : 'pronto',
            'mensagem' => $jaEstavaPronto
                ? 'Essa já tinha sido bipada às '.$jaEstavaPronto->format('H:i').'.'
                : 'Pronto pra coleta.',
            'ja_estava_pronto_em' => $jaEstavaPronto?->format('d/m/Y H:i'),
            'venda' => $this->paraTela($order->loadMissing(['items', 'channelShipment'])),
            'no_pack' => $irmaos->count() > 1 ? $irmaos->pluck('id')->all() : null,
        ];
    }

    /**
     * Desfaz uma bipada — bipou a caixa errada, ou a coleta não aconteceu e
     * a caixa voltou pra prateleira. Só limpa o "pronto"; não desfaz a
     * separação, que continua verdadeira.
     *
     * @return array<string, mixed>
     */
    public function desfazer(int $orderId): array
    {
        $order = Order::query()->find($orderId);

        if (! $order) {
            return $this->recusa('nao_encontrada', "Pedido #{$orderId} não existe.");
        }

        if (! $order->ready_for_pickup_at) {
            return $this->recusa('nao_estava_pronto', 'Essa venda não estava marcada como pronta.', $order);
        }

        $order->forceFill(['ready_for_pickup_at' => null])->save();

        $this->timeline->record(
            $order,
            OrderFulfillmentEvent::STEP_READY_FOR_PICKUP,
            OrderFulfillmentEvent::STATUS_FAILED,
            'Bipagem desfeita no KoraFlex',
        );

        return [
            'ok' => true,
            'motivo' => 'desfeito',
            'mensagem' => 'Voltou pra pendente.',
            'venda' => $this->paraTela($order->loadMissing(['items', 'channelShipment'])),
        ];
    }

    /** @return Collection<int, Order> */
    private function pedidosDoMesmoEnvio(ChannelShipment $shipment): Collection
    {
        if (! $shipment->external_shipment_id) {
            return collect([$shipment->order]);
        }

        $ids = ChannelShipment::query()
            ->where('channel', $shipment->channel)
            ->where('external_shipment_id', $shipment->external_shipment_id)
            ->pluck('order_id')
            ->unique();

        return Order::query()
            ->whereIn('id', $ids)
            ->where('status', '!=', Order::STATUS_CANCELLED)
            ->get();
    }

    /**
     * Só Mercado Livre + self_service: a coleta do Flex é a do Mercado
     * Livre. Shopee tem coleta própria e TikTok nem passa por aqui.
     */
    private function flexQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Order::query()
            ->nonPurchaseReturn()
            ->where('orders.origin', Order::ORIGIN_MERCADO_LIVRE)
            ->where('orders.status', '!=', Order::STATUS_CANCELLED)
            ->whereHas('channelShipment', function ($query) {
                $query->where('channel', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)
                    ->where('shipping_method', ChannelShipment::METHOD_FLEX);
            })
            ->with(['items:id,order_id,product_id,product_name,quantity', 'items.product:id,sku', 'channelShipment'])
            ->orderBy('orders.created_at');
    }

    /** @return array<string, mixed> */
    private function paraTela(Order $order): array
    {
        $shipment = $order->channelShipment;

        return [
            'pedido' => $order->id,
            'venda' => $order->external_order_id,
            'envio' => $shipment?->external_shipment_id,
            'rastreio' => $shipment?->tracking_code,
            'cliente' => $this->nomeDoCliente($order),
            'cidade' => trim(($order->shipping_city ?: '').($order->shipping_state ? '/'.$order->shipping_state : '')),
            'bairro' => $order->shipping_neighborhood,
            'vendida_em' => $order->created_at?->format('d/m H:i'),
            'itens' => $order->items->map(fn ($item) => [
                'sku' => $item->product?->sku,
                'nome' => $item->product_name,
                'qtd' => (int) $item->quantity,
            ])->values()->all(),
            'pecas' => (int) $order->items->sum('quantity'),
            'estado' => $this->estado($order),
            'separado_em' => $order->packed_at?->format('d/m H:i'),
            'pronto_em' => $order->ready_for_pickup_at?->format('d/m H:i'),
            'etiqueta_impressa' => $this->etiquetaImpressa($order),
        ];
    }

    private function estado(Order $order): string
    {
        if (in_array($order->status, [Order::STATUS_SHIPPED, Order::STATUS_COMPLETED], true)) {
            return self::ESTADO_COLETADO;
        }

        return $order->ready_for_pickup_at ? self::ESTADO_PRONTO : self::ESTADO_PENDENTE;
    }

    private function etiquetaImpressa(Order $order): bool
    {
        return PrintJob::query()
            ->where('order_id', $order->id)
            ->where('is_thank_you', false)
            ->where('status', PrintJob::STATUS_PRINTED)
            ->exists();
    }

    /** Mesma regra de exibição da fila do KoraSync: nome mascarado não engana ninguém. */
    private function nomeDoCliente(Order $order): string
    {
        $nome = trim((string) ($order->shipping_recipient_name ?: $order->shipping_name));

        if ($nome === '' || str_contains($nome, '*')) {
            return 'Cliente (dados ocultados pelo canal)';
        }

        return $nome;
    }

    /**
     * Aceita o QR inteiro do Flex (JSON), o JSON já extraído, ou só o
     * número — celular velho, leitor externo e digitação manual chegam de
     * jeitos diferentes e todos têm que funcionar com a caixa na mão.
     */
    public function parseQr(string $qr): ?string
    {
        $qr = trim($qr);

        if ($qr === '') {
            return null;
        }

        // O ZPL prefixa o conteúdo com "LA," (modo alfanumérico do ^BQ);
        // alguns leitores entregam isso junto.
        $limpo = preg_replace('/^LA,/i', '', $qr) ?? $qr;

        $json = json_decode($limpo, true);

        if (is_array($json) && isset($json['id'])) {
            return preg_replace('/\D/', '', (string) $json['id']) ?: null;
        }

        // Número puro (o próprio id do envio, ou o rastreio digitado).
        if (preg_match('/^\d{6,20}$/', $limpo)) {
            return $limpo;
        }

        // Última tentativa: um id de envio no meio de qualquer texto.
        if (preg_match('/"id"\s*:\s*"?(\d{6,20})"?/', $limpo, $m)) {
            return $m[1];
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function recusa(string $motivo, string $mensagem, ?Order $order = null, ?ChannelShipment $shipment = null): array
    {
        return [
            'ok' => false,
            'motivo' => $motivo,
            'mensagem' => $mensagem,
            'venda' => $order ? $this->paraTela($order->loadMissing(['items', 'channelShipment'])) : null,
        ];
    }

    /** @return array{0: int, 1: int} */
    private function corte(): array
    {
        $corte = (string) config('services.koraflex.cutoff', '12:00');

        if (! preg_match('/^(\d{1,2}):(\d{2})$/', trim($corte), $m)) {
            Log::warning('koraflex.corte_invalido', ['valor' => $corte]);

            return [12, 0];
        }

        return [(int) $m[1], (int) $m[2]];
    }

    private function corteFormatado(): string
    {
        [$hora, $minuto] = $this->corte();

        return sprintf('%02d:%02d', $hora, $minuto);
    }
}
