<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Support\PackDoPedido;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Services\MercadoLivre\MercadoLivreClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * O que a emissão da NF-e precisa perguntar ao Mercado Livre antes de montar
 * a nota de um carrinho (pack). A regra fiscal em si está em PackDoPedido.
 *
 * O ML avisa pedido por pedido. Se a nota do carrinho saísse no aviso do 1º
 * pedido, com o 2º ainda a caminho, sairia de novo uma nota pela metade —
 * o mesmo defeito de 2026-09-11 por outro caminho. Por isso a nota só sai
 * depois de conferir no próprio ML quais pedidos o carrinho tem, e de todos
 * estarem importados e pagos.
 */
class MercadoLivrePackInvoiceGate
{
    public function __construct(
        private readonly MercadoLivreClient $client,
        private readonly OrderImportService $orderImport,
        private readonly PackDoPedido $pack,
    ) {
    }

    /**
     * pack_id do pedido, ou null se não é carrinho.
     *
     * Pedido importado antes de 2026-09-11 não tem a coluna preenchida. Só
     * vale gastar uma chamada na API quando há sinal local de carrinho: outro
     * pedido dividindo o mesmo envio.
     */
    public function packId(Order $order): ?string
    {
        if ($order->origin !== Order::ORIGIN_MERCADO_LIVRE) {
            return null;
        }

        if ($order->channel_pack_id) {
            return $order->channel_pack_id;
        }

        $order->loadMissing('channelShipment');
        $envio = $order->channelShipment?->external_shipment_id;

        if (! $envio) {
            return null;
        }

        $irmaos = ChannelShipment::query()
            ->where('channel', Order::ORIGIN_MERCADO_LIVRE)
            ->where('external_shipment_id', $envio)
            ->where('order_id', '!=', $order->id)
            ->pluck('order_id');

        if ($irmaos->isEmpty()) {
            return null;
        }

        $packId = $this->client->get("orders/{$order->external_order_id}")['pack_id'] ?? null;

        if (! $packId) {
            return null;
        }

        Order::query()
            ->whereIn('id', $irmaos->push($order->id))
            ->where('origin', Order::ORIGIN_MERCADO_LIVRE)
            ->whereNull('channel_pack_id')
            ->update(['channel_pack_id' => (string) $packId]);

        $order->channel_pack_id = (string) $packId;

        return (string) $packId;
    }

    /**
     * Garante que todos os pedidos do carrinho estão no banco e pagos.
     * Importa na hora o que faltar; se ainda faltar, lança — o job de nota
     * tenta de novo com backoff, e o nfe:retry-stuck pega depois.
     */
    public function garantirCompleto(Order $order, string $packId): void
    {
        // Achado no ar, 2026-09-11: o ML dá pack_id (e a tag pack_order) pra
        // TODO pedido — venda de um anúncio só vira um "pack" de 1 pedido.
        // Então esta consulta roda pra toda nota do ML. Se ela falhar (429,
        // timeout) e não houver irmão no banco, a nota segue como pedido
        // avulso, exatamente como era antes: um soluço da API não pode
        // atrasar a etiqueta da venda comum. Com irmão no banco o carrinho é
        // certo, e aí a nota espera.
        try {
            $resposta = $this->client->get("packs/{$packId}");
        } catch (Throwable $exception) {
            if ($this->pack->pedidos($order)->count() > 1) {
                throw $exception;
            }

            Log::warning('nfe.pack.consulta_falhou_segue_avulso', [
                'order_id' => $order->id,
                'pack_id' => $packId,
                'message' => $exception->getMessage(),
            ]);

            return;
        }

        $externos = collect($resposta['orders'] ?? [])
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->values();

        if ($externos->isEmpty()) {
            throw new RuntimeException("Carrinho {$packId}: o Mercado Livre não devolveu os pedidos do pack — a nota espera.");
        }

        foreach ($this->faltando($externos) as $externo) {
            $this->orderImport->import(Order::ORIGIN_MERCADO_LIVRE, $externo);
        }

        $faltando = $this->faltando($externos);

        if ($faltando->isNotEmpty()) {
            throw new RuntimeException("Carrinho {$packId}: pedido(s) {$faltando->implode(', ')} ainda não importado(s) — a nota do carrinho só sai com todos.");
        }

        Order::query()
            ->where('origin', Order::ORIGIN_MERCADO_LIVRE)
            ->whereIn('external_order_id', $externos)
            ->whereNull('channel_pack_id')
            ->update(['channel_pack_id' => $packId]);

        $order->channel_pack_id = $packId;

        $aguardando = $this->pack->ativos($order)
            ->filter(fn (Order $pedido) => in_array($pedido->status, [Order::STATUS_PENDING, Order::STATUS_AWAITING_PAYMENT], true));

        if ($aguardando->isNotEmpty()) {
            throw new RuntimeException("Carrinho {$packId}: pedido(s) #{$aguardando->pluck('id')->implode(', #')} ainda sem pagamento confirmado — a nota do carrinho espera.");
        }
    }

    /**
     * @param  Collection<int, string>  $externos
     * @return Collection<int, string>
     */
    private function faltando(Collection $externos): Collection
    {
        $locais = Order::query()
            ->where('origin', Order::ORIGIN_MERCADO_LIVRE)
            ->whereIn('external_order_id', $externos)
            ->pluck('external_order_id')
            ->map(fn ($id) => (string) $id);

        return $externos->diff($locais)->values();
    }
}
