<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Support\OrderPaymentFinalizer;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\MarketplaceClaim;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reclamações/devoluções do Mercado Livre (tópico post_purchase — ver
 * ClaimService). Pedido explícito do usuário 2026-08-05: só rastrear e
 * mostrar, NÃO reverter estoque/receita sozinho — reversão de estoque é uma
 * ação manual daqui (revertStock()), olhando caso a caso.
 */
class MercadoLivreClaimsController extends Controller
{
    private const TYPE_LABELS = [
        'mediations' => 'Mediação',
        'returns' => 'Devolução',
        'cancellations' => 'Cancelamento',
    ];

    private const STAGE_LABELS = [
        'claim' => 'Reclamação',
        'dispute' => 'Mediação (Mercado Livre)',
        'recontact' => 'Recontato',
    ];

    private const STATUS_LABELS = [
        'opened' => 'Aberta',
        'closed' => 'Fechada',
    ];

    public function index(): Response
    {
        $claims = MarketplaceClaim::query()
            ->where('channel', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)
            ->with('order:id,external_order_id,shipping_name,total,stock_restored_at')
            ->latest('claim_created_at')
            ->get()
            ->map(fn (MarketplaceClaim $claim) => [
                'id' => $claim->id,
                'externalClaimId' => $claim->external_claim_id,
                'orderId' => $claim->order_id,
                'externalOrderId' => $claim->order?->external_order_id,
                'customer' => $claim->order?->shipping_name,
                'total' => $claim->order ? (float) $claim->order->total : null,
                'type' => $claim->type,
                'typeLabel' => self::TYPE_LABELS[$claim->type] ?? ($claim->type ?? 'Não informado'),
                'stage' => $claim->stage,
                'stageLabel' => self::STAGE_LABELS[$claim->stage] ?? ($claim->stage ?? '—'),
                'status' => $claim->status,
                'statusLabel' => self::STATUS_LABELS[$claim->status] ?? ($claim->status ?? 'Não informado'),
                'reasonId' => $claim->reason_id,
                'stockRestoredAt' => $claim->order?->stock_restored_at,
                'canRevertStock' => $claim->order && ! $claim->order->stock_restored_at,
                'claimCreatedAt' => $claim->claim_created_at,
            ]);

        return Inertia::render('Admin/Integracoes/MercadoLivre/Devolucoes', [
            'claims' => $claims,
        ]);
    }

    public function revertStock(MarketplaceClaim $marketplaceClaim, OrderPaymentFinalizer $finalizer): RedirectResponse
    {
        $claim = $marketplaceClaim;

        if (! $claim->order) {
            return back()->with('error', 'Esse claim não está vinculado a nenhum pedido local — não tem estoque pra reverter.');
        }

        if ($claim->order->stock_restored_at) {
            return back()->with('warning', 'O estoque desse pedido já tinha sido revertido em '.$claim->order->stock_restored_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i').'.');
        }

        $finalizer->restoreStockIfNeeded($claim->order, "Devolução Mercado Livre — reclamação #{$claim->external_claim_id}");

        return back()->with('success', "Estoque do pedido #{$claim->order_id} revertido.");
    }
}
