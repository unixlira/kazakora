<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vendas do Mercado Livre com valores bruto/taxa/líquido — os dados já
 * existiam (Order + OrderChannelFee, populados por
 * MercadoLivreDriver::importOrder() com o `sale_fee` real de cada item, não
 * estimado), só não tinha tela pra ver. Pedido do usuário 2026-08-05.
 */
class MercadoLivreSalesController extends Controller
{
    public function index(): Response
    {
        $orders = Order::query()
            ->where('origin', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)
            ->with(['channelFee', 'user:id,name'])
            ->withCount('items')
            ->latest()
            ->get()
            ->map(function (Order $order) {
                // Sem OrderChannelFee (canal não devolveu sale_fee, ou pedido
                // anterior a essa integração), gross vira o total do pedido e
                // não existe taxa/líquido conhecidos — mostrado como "—" no
                // front, nunca inventado como zero.
                $gross = (float) ($order->channelFee?->gross_amount ?? $order->total);
                $fee = $order->channelFee?->fee_amount;

                return [
                    'id' => $order->id,
                    'externalOrderId' => $order->external_order_id,
                    'customer' => $order->shipping_name ?: $order->user?->name,
                    'itemsCount' => $order->items_count,
                    'status' => $order->status,
                    'gross' => $gross,
                    'fee' => $fee !== null ? (float) $fee : null,
                    'net' => $order->channelFee ? $order->channelFee->netAmount() : null,
                    'createdAt' => $order->created_at,
                ];
            });

        $withFeeData = $orders->filter(fn (array $order) => $order['fee'] !== null);

        return Inertia::render('Admin/Integracoes/MercadoLivre/Vendas', [
            'orders' => $orders->values(),
            'summary' => [
                'count' => $orders->count(),
                'grossTotal' => round($orders->sum('gross'), 2),
                // Soma taxa/líquido só sobre pedidos com dado real da API —
                // misturar com o fallback (gross=total, sem taxa) inflaria o
                // líquido total silenciosamente.
                'feeTotal' => round($withFeeData->sum('fee'), 2),
                'netTotal' => round($withFeeData->sum('net'), 2),
                'withFeeDataCount' => $withFeeData->count(),
            ],
        ]);
    }
}
