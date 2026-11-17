<?php

namespace App\Modules\Checkout\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Models\MelhorEnvioEnvio;
use Illuminate\Support\Carbon;

/**
 * O pacote do pedido já foi postado? (Lira, 2026-11-17). Depois da postagem
 * o cliente não cancela mais sozinho: o botão de Meus pedidos vira uma
 * solicitação para a equipe (CancelamentoDoPedido::solicitarPeloCliente).
 *
 * Conta como postado qualquer um destes:
 * - Melhor Envio com postada_em/entregue_em ou situação posted/received/delivered;
 * - pré-postagem dos Correios de envio que o rastreio viu postada ou
 *   entregue (só gerada/pré-postada ou "expirada" NÃO conta: o pacote
 *   nunca passou pela agência);
 * - envio do canal que já saiu (ChannelShipment::jaSaiu, channel_shipped_at);
 * - pedido com status enviado ou concluído.
 */
class PostagemDoPedido
{
    /** Situações do Melhor Envio que já são pacote na transportadora. */
    private const SITUACOES_MELHOR_ENVIO = ['posted', 'received', 'delivered'];

    /**
     * Onde e quando o pacote foi postado, ou null se ainda não foi.
     *
     * @return array{fonte: string, postado_em: ?Carbon, rastreio: ?string}|null
     */
    public function encontrar(Order $order): ?array
    {
        $melhorEnvio = MelhorEnvioEnvio::query()
            ->where('order_id', $order->id)
            ->where(fn ($q) => $q->whereNotNull('postada_em')
                ->orWhereNotNull('entregue_em')
                ->orWhereIn('situacao', self::SITUACOES_MELHOR_ENVIO))
            ->latest('id')
            ->first();

        if ($melhorEnvio) {
            return $this->postagem('Melhor Envio', $melhorEnvio->postada_em ?? $melhorEnvio->entregue_em, $melhorEnvio->rastreio, $order);
        }

        $correios = CorreiosPrePostagem::query()
            ->deEnvio()
            ->where('order_id', $order->id)
            ->where('status', CorreiosPrePostagem::STATUS_GERADA)
            ->where(fn ($q) => $q->whereNotNull('postada_em')
                ->orWhereNotNull('entregue_em')
                ->orWhereIn('rastreio_situacao', [CorreiosPrePostagem::SITUACAO_POSTADA, CorreiosPrePostagem::SITUACAO_ENTREGUE]))
            ->latest('id')
            ->first();

        if ($correios) {
            return $this->postagem('Correios', $correios->postada_em ?? $correios->entregue_em, $correios->codigo_objeto, $order);
        }

        $canal = ChannelShipment::query()->where('order_id', $order->id)->jaSaiu()->latest('id')->first();

        if ($canal) {
            return $this->postagem('Envio do canal', $canal->channel_shipped_at ?? $canal->channel_delivered_at, $canal->tracking_code, $order);
        }

        if (in_array($order->status, [Order::STATUS_SHIPPED, Order::STATUS_COMPLETED], true)) {
            return $this->postagem('Status do pedido', null, null, $order);
        }

        return null;
    }

    public function foiPostado(Order $order): bool
    {
        return $this->encontrar($order) !== null;
    }

    /** @return array{fonte: string, postado_em: ?Carbon, rastreio: ?string} */
    private function postagem(string $fonte, mixed $em, ?string $rastreio, Order $order): array
    {
        return [
            'fonte' => $fonte,
            'postado_em' => $em ? Carbon::parse($em) : null,
            'rastreio' => filled($rastreio) ? $rastreio : $order->trackingCode(),
        ];
    }
}
