<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Support\Publicidade\PlataformaDeAnuncio;
use App\Modules\Marketplace\Models\ChannelAdSpend;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Seções "Meta ADS" e "Google ADS" do menu Publicidade (pedido do Lira em
 * 2026-10-12). Só leitura. Hoje não há integração com nenhuma das duas
 * (ver PlataformaDeAnuncio): a tela mostra "integração ainda não
 * conectada" e, quando o gasto passar a ser sincronizado em
 * channel_ad_spends, mostra os números sem precisar mudar esta tela.
 *
 * ROAS aqui é o da própria plataforma (venda atribuída / gasto), não o
 * lucro: lucro é sempre ContributionMargin.
 */
class PublicidadePlataformaController extends Controller
{
    public function metaAds(): Response
    {
        return $this->exibir(PlataformaDeAnuncio::META);
    }

    public function googleAds(): Response
    {
        return $this->exibir(PlataformaDeAnuncio::GOOGLE);
    }

    private function exibir(string $canal): Response
    {
        $hoje = Carbon::now();
        $inicioMes = $hoje->copy()->startOfMonth();
        $inicioAnterior = $hoje->copy()->subMonthNoOverflow()->startOfMonth();
        $fimAnterior = $hoje->copy()->subMonthNoOverflow()->endOfMonth();

        $ultimaSincronizacao = ChannelAdSpend::query()->where('channel', $canal)->max('date');

        return Inertia::render('Admin/Publicidade/Plataforma', [
            'plataforma' => PlataformaDeAnuncio::dados($canal),
            'conectado' => $ultimaSincronizacao !== null,
            'ultimaSincronizacao' => $ultimaSincronizacao ? Carbon::parse($ultimaSincronizacao)->toDateString() : null,
            'periodos' => [
                [
                    'titulo' => 'Mês atual',
                    'desde' => $inicioMes->toDateString(),
                    'ate' => $hoje->toDateString(),
                    'totais' => $this->totais($canal, $inicioMes, $hoje),
                ],
                [
                    'titulo' => 'Mês anterior',
                    'desde' => $inicioAnterior->toDateString(),
                    'ate' => $fimAnterior->toDateString(),
                    'totais' => $this->totais($canal, $inicioAnterior, $fimAnterior),
                ],
            ],
        ]);
    }

    /**
     * @return array{gasto: float, impressoes: int, cliques: int, pedidos: int, vendas: float, roas: float|null}
     */
    private function totais(string $canal, Carbon $desde, Carbon $ate): array
    {
        $linha = ChannelAdSpend::query()
            ->where('channel', $canal)
            ->whereBetween('date', [$desde->toDateString(), $ate->toDateString()])
            ->selectRaw('COALESCE(SUM(spend), 0) as gasto, COALESCE(SUM(impressions), 0) as impressoes, COALESCE(SUM(clicks), 0) as cliques, COALESCE(SUM(attributed_orders), 0) as pedidos, COALESCE(SUM(attributed_gmv), 0) as vendas')
            ->first();

        $gasto = round((float) $linha->gasto, 2);
        $vendas = round((float) $linha->vendas, 2);

        return [
            'gasto' => $gasto,
            'impressoes' => (int) $linha->impressoes,
            'cliques' => (int) $linha->cliques,
            'pedidos' => (int) $linha->pedidos,
            'vendas' => $vendas,
            'roas' => $gasto > 0 ? round($vendas / $gasto, 2) : null,
        ];
    }
}
