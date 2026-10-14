<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Support\Diagnostico\EstrategiaShopeeAds;
use App\Services\Shopee\ShopeeAdsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Publicidade › Estratégia Shopee (pedido do Lira em 2026-10-14): campanhas
 * de ADS com prejuízo pelo ROAS × empate e pela conversão, para decidir de
 * olho. Fase 1, SÓ LEITURA: nenhum botão muda nada na Shopee. Os números
 * vêm de shopee_ad_campanhas/_dias (comando shopee:ads-campanhas).
 */
class PublicidadeShopeeController extends Controller
{
    public const PERIODOS = [7, 14, 30, 60];

    public function index(Request $request, EstrategiaShopeeAds $estrategia, ShopeeAdsService $ads): Response
    {
        $dados = $request->validate([
            'dias' => ['nullable', 'integer', 'in:'.implode(',', self::PERIODOS)],
            'desde' => ['nullable', 'date', 'required_with:ate'],
            'ate' => ['nullable', 'date', 'after_or_equal:desde', 'required_with:desde'],
        ]);

        if (! empty($dados['desde'])) {
            $desde = Carbon::parse($dados['desde']);
            $ate = Carbon::parse($dados['ate'])->min(Carbon::today());
            $dias = null;
        } else {
            $dias = (int) ($dados['dias'] ?? 30);
            $ate = Carbon::today();
            $desde = $ate->copy()->subDays($dias - 1);
        }

        return Inertia::render('Admin/Publicidade/Shopee', [
            'estrategia' => $estrategia->gerar($desde, $ate),
            'filtro' => ['dias' => $dias, 'desde' => $desde->toDateString(), 'ate' => $ate->toDateString()],
            'periodos' => self::PERIODOS,
            'saldo' => $this->saldo($ads),
        ]);
    }

    /** Saldo de ADS de agora; sem acesso à API (homolog) fica em branco. */
    private function saldo(ShopeeAdsService $ads): ?float
    {
        $saldo = Cache::get('publicidade.shopee.saldo');
        if ($saldo !== null) {
            return (float) $saldo;
        }
        try {
            $saldo = $ads->currentBalance();
            Cache::put('publicidade.shopee.saldo', $saldo, now()->addMinutes(15));

            return $saldo;
        } catch (Throwable) {
            return null;
        }
    }
}
