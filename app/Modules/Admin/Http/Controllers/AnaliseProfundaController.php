<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Support\Diagnostico\AnaliseProfundaShopee;
use App\Services\Shopee\ShopeeAdsService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Estatísticas › Análise Profunda de Conta (pedido do Lira em 2026-10-26):
 * todos os dados da Shopee numa tela, risco primeiro. Só leitura. A rota
 * aceita {canal} para o Mercado Livre entrar depois sem rota nova; hoje só
 * shopee.
 */
class AnaliseProfundaController extends Controller
{
    public const CANAIS = ['shopee' => 'Shopee'];

    public const PERIODOS = [7, 15, 30, 60, 90];

    /** Ao abrir: 7 dias fechados (até ontem), o período que a coleta da tela já traz pronto. */
    private const DIAS_PADRAO = 7;

    public function show(Request $request, string $canal, AnaliseProfundaShopee $analise, ShopeeAdsService $ads): Response
    {
        abort_unless(isset(self::CANAIS[$canal]), 404);

        $dados = $request->validate([
            'dias' => ['nullable', 'integer', 'in:'.implode(',', self::PERIODOS)],
            'desde' => ['nullable', 'date_format:Y-m-d', 'required_with:ate'],
            'ate' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde', 'required_with:desde'],
        ]);

        if (! empty($dados['desde'])) {
            $ate = Carbon::parse($dados['ate'])->startOfDay()->min(Carbon::today());
            $desde = Carbon::parse($dados['desde'])->startOfDay()->max($ate->copy()->subDays(365));
            $dias = null;
        } else {
            $dias = (int) ($dados['dias'] ?? self::DIAS_PADRAO);
            $ate = Carbon::yesterday();
            $desde = $ate->copy()->subDays($dias - 1);
        }

        return Inertia::render('Admin/Estatisticas/AnaliseProfunda', [
            'canal' => ['slug' => $canal, 'nome' => self::CANAIS[$canal]],
            'filtro' => ['dias' => $dias, 'desde' => $desde->toDateString(), 'ate' => $ate->toDateString()],
            'periodos' => self::PERIODOS,
            'analise' => $analise->gerar($desde, $ate),
            'saldo' => $this->saldo($ads),
        ]);
    }

    /** Saldo de ADS de agora (mesmo cache da Estratégia Shopee); sem API fica em branco. */
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
