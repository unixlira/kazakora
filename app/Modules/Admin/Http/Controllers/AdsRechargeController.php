<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Models\AdsRecharge;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Services\Shopee\ShopeeAdsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

    public function index(Request $request, ShopeeAdsService $shopeeAds): Response
    {
        $shopeeAccount = MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)->first();
        $shopeeBalance = null;

        if ($shopeeAccount?->isConnected()) {
            try {
                // get_total_balance ao vivo custava ~1,2s em todo carregamento
                // da tela (medido em produção 2026-09-29) — saldo de anúncio
                // de referência não precisa ser do segundo exato, fica em
                // cache por alguns minutos por loja. Exceção não é cacheada
                // (sai do remember antes de gravar). ?refresh=1 força buscar
                // de novo; registrar/remover recarga também limpa o cache,
                // já que é exatamente quando o saldo acabou de mudar.
                $cacheKey = self::balanceCacheKey($shopeeAccount);

                if ($request->boolean('refresh')) {
                    Cache::forget($cacheKey);
                }

                $shopeeBalance = Cache::remember($cacheKey, now()->addMinutes(5), fn () => $shopeeAds->currentBalance());
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
        self::forgetBalanceCache();

        return back()->with('success', 'Recarga registrada.');
    }

    public function destroy(AdsRecharge $adsRecharge): RedirectResponse
    {
        $adsRecharge->delete();
        self::forgetBalanceCache();

        return back()->with('success', 'Recarga removida.');
    }

    private static function balanceCacheKey(MarketplaceAccount $account): string
    {
        return 'shopee:ads_balance:'.$account->seller_id;
    }

    private static function forgetBalanceCache(): void
    {
        $account = MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_SHOPEE)->first();

        if ($account) {
            Cache::forget(self::balanceCacheKey($account));
        }
    }
}
