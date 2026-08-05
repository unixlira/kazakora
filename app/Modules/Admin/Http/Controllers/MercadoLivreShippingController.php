<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tipos de envio por venda no Mercado Livre, com filtro — `shipping_method`
 * já guarda o `logistic_type` real devolvido pela API
 * (MercadoLivreDriver::confirmShipping()), só faltava uma tela. Valores
 * reais confirmados ao vivo: self_service (Flex), fulfillment (Full),
 * drop_off/xd_drop_off (coleta — o que o usuário chama de "PAC", ML não usa
 * esse nome, é o serviço dos Correios por trás da coleta padrão). Pedido do
 * usuário 2026-08-05.
 */
class MercadoLivreShippingController extends Controller
{
    private const METHOD_LABELS = [
        'self_service' => 'Flex',
        'fulfillment' => 'Full',
        'drop_off' => 'Coleta / Correios (PAC)',
        'xd_drop_off' => 'Coleta / Cross-docking',
        'unknown' => 'Não informado',
    ];

    public function index(Request $request): Response
    {
        $shipments = ChannelShipment::query()
            ->where('channel', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)
            ->with('order:id,external_order_id,shipping_name,shipping_city,shipping_state,total,status')
            ->when($request->filled('tipo'), fn ($query) => $query->where('shipping_method', $request->string('tipo')))
            ->latest()
            ->get()
            ->map(fn (ChannelShipment $shipment) => [
                'id' => $shipment->id,
                'orderId' => $shipment->order_id,
                'externalOrderId' => $shipment->order?->external_order_id,
                'customer' => $shipment->order?->shipping_name,
                'city' => $shipment->order?->shipping_city,
                'state' => $shipment->order?->shipping_state,
                'total' => $shipment->order ? (float) $shipment->order->total : null,
                'shippingMethod' => $shipment->shipping_method,
                'shippingMethodLabel' => self::METHOD_LABELS[$shipment->shipping_method] ?? ($shipment->shipping_method ?? 'Não informado'),
                'status' => $shipment->status,
                'confirmedAt' => $shipment->confirmed_at,
            ]);

        // Contagem por tipo sobre TODOS os envios (não só a página filtrada)
        // pra alimentar os cartões/abas de filtro com o total real de cada
        // categoria, mesmo quando um filtro já está aplicado na tabela.
        $counts = ChannelShipment::query()
            ->where('channel', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)
            ->selectRaw('shipping_method, count(*) as total')
            ->groupBy('shipping_method')
            ->pluck('total', 'shipping_method');

        return Inertia::render('Admin/Integracoes/MercadoLivre/TiposDeEnvio', [
            'shipments' => $shipments,
            'filters' => $request->only('tipo'),
            'methodOptions' => collect(self::METHOD_LABELS)
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label, 'count' => (int) $counts->get($value, 0)])
                ->values(),
            'totalCount' => (int) $counts->sum(),
        ]);
    }
}
