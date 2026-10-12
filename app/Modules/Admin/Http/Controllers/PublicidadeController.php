<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Models\ChannelAdSpend;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Menu "Publicidade" (pedido do Lira em 2026-10-12): página de entrada que
 * resume o gasto de ADS por canal (tabela channel_ad_spends, a mesma que a
 * ContributionMargin desconta do lucro) no mês atual e no anterior, com
 * atalhos para as telas de publicidade que já existiam. Só leitura.
 *
 * ROAS aqui é o da própria plataforma (venda atribuída ao anúncio / gasto),
 * não o lucro: lucro é sempre ContributionMargin.
 */
class PublicidadeController extends Controller
{
    public function index(): Response
    {
        $hoje = Carbon::now();

        return Inertia::render('Admin/Publicidade/Index', [
            'periodos' => [
                [
                    'titulo' => 'Mês atual',
                    'desde' => $hoje->copy()->startOfMonth()->toDateString(),
                    'ate' => $hoje->toDateString(),
                    'canais' => $this->resumo($hoje->copy()->startOfMonth(), $hoje),
                ],
                [
                    'titulo' => 'Mês anterior',
                    'desde' => $hoje->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                    'ate' => $hoje->copy()->subMonthNoOverflow()->endOfMonth()->toDateString(),
                    'canais' => $this->resumo(
                        $hoje->copy()->subMonthNoOverflow()->startOfMonth(),
                        $hoje->copy()->subMonthNoOverflow()->endOfMonth(),
                    ),
                ],
            ],
        ]);
    }

    /**
     * @return array<int, array{canal: string, gasto: float, impressoes: int, cliques: int, pedidos: int, vendas: float, roas: float|null}>
     */
    private function resumo(Carbon $desde, Carbon $ate): array
    {
        return ChannelAdSpend::query()
            ->whereBetween('date', [$desde->toDateString(), $ate->toDateString()])
            ->groupBy('channel')
            ->orderBy('channel')
            ->selectRaw('channel, SUM(spend) as gasto, SUM(impressions) as impressoes, SUM(clicks) as cliques, SUM(attributed_orders) as pedidos, SUM(attributed_gmv) as vendas')
            ->get()
            ->map(function ($linha) {
                $gasto = round((float) $linha->gasto, 2);
                $vendas = round((float) $linha->vendas, 2);

                return [
                    'canal' => $linha->channel,
                    'gasto' => $gasto,
                    'impressoes' => (int) $linha->impressoes,
                    'cliques' => (int) $linha->cliques,
                    'pedidos' => (int) $linha->pedidos,
                    'vendas' => $vendas,
                    'roas' => $gasto > 0 ? round($vendas / $gasto, 2) : null,
                ];
            })
            ->values()
            ->all();
    }
}
