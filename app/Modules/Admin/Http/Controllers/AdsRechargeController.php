<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Models\AdsRecharge;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Services\Shopee\ShopeeAdsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Pedido explícito 2026-08-09: histórico de recarga de saldo de anúncio
 * (Shopee Ads / Mercado Ads). Lançado à mão — nenhuma das duas APIs expõe
 * um extrato de recarga consultável (ver AdsRecharge). O saldo ATUAL da
 * Shopee, esse sim, é real e vem direto da API — mostrado como referência
 * ao lado da lista manual, não confundir os dois.
 */
class AdsRechargeController extends Controller
{
    private const CHANNELS = [MarketplaceAccount::CHANNEL_SHOPEE, MarketplaceAccount::CHANNEL_MERCADO_LIVRE];

    public function index(ShopeeAdsService $shopeeAds): Response
    {
        $shopeeConnected = MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)->first()?->isConnected();
        $shopeeBalance = null;

        if ($shopeeConnected) {
            try {
                $shopeeBalance = $shopeeAds->currentBalance();
            } catch (Throwable) {
                // Best-effort — a lista de recargas não pode ficar
                // indisponível só porque a consulta de saldo ao vivo falhou.
                $shopeeBalance = null;
            }
        }

        $recharges = AdsRecharge::query()->with('creator:id,name')->latest('recharge_date')->get();

        return Inertia::render('Admin/AdsRecharges/Index', [
            'recharges' => $recharges,
            'shopeeBalance' => $shopeeBalance,
            'summary' => [
                'shopee' => (float) $recharges->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)->sum('amount'),
                'mercado_livre' => (float) $recharges->where('channel', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)->sum('amount'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'channel' => ['required', Rule::in(self::CHANNELS)],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'recharge_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $validated['created_by'] = $request->user()->id;

        AdsRecharge::create($validated);

        return back()->with('success', 'Recarga registrada.');
    }

    public function destroy(AdsRecharge $adsRecharge): RedirectResponse
    {
        $adsRecharge->delete();

        return back()->with('success', 'Recarga removida.');
    }
}
