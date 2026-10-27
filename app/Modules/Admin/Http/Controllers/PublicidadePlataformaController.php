<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Support\Publicidade\PlataformaDeAnuncio;
use App\Modules\Marketplace\Models\ChannelAdSpend;
use App\Modules\Marketplace\Models\MetaAdsCampanhaHistorico;
use App\Services\Meta\MetaAdsException;
use App\Services\Meta\MetaAdsService;
use Illuminate\Support\Arr;
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

    /**
     * Lira 2026-10-27: botão Visualizar do histórico. Campanha excluída com
     * os conjuntos e o desempenho de cada anúncio, da foto de 27/10.
     */
    public function historicoMeta(MetaAdsCampanhaHistorico $historico): Response
    {
        return Inertia::render('Admin/Publicidade/HistoricoCampanhaMeta', [
            'campanha' => Arr::only($historico->toArray(), [
                'id', 'meta_id', 'nome', 'status_final', 'objetivo', 'orcamento_nivel', 'orcamento_diario',
                'inicio', 'fim', 'excluida_em', 'gasto', 'impressoes', 'alcance', 'cliques_link', 'ctr_link',
                'compras', 'conjuntos', 'anuncios',
            ]),
            'conjuntos' => $historico->estrutura ?? [],
        ]);
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

        // Meta: versão compacta do "O que fazer agora" (2026-10-21), últimos
        // 7 dias, lida ao vivo da Meta (prop adiada para não travar a tela).
        $metaConectada = $canal === PlataformaDeAnuncio::META && app(MetaAdsService::class)->configurado();
        $extras = $metaConectada
            ? ['diagnostico' => Inertia::defer(fn () => $this->diagnosticoMeta()), 'temDiagnostico' => true]
            : ['temDiagnostico' => false];

        // Lira 2026-10-27: sem gasto diário gravado, os números do mês vêm
        // direto da Meta (nível conta, inclui campanha excluída), adiados
        // para não travar a tela.
        if ($metaConectada && $ultimaSincronizacao === null) {
            $extras['resumoMeta'] = Inertia::defer(fn () => $this->resumoMeta($inicioMes, $hoje, $inicioAnterior, $fimAnterior));
        }

        // Lira 2026-10-27: histórico das campanhas excluídas, da nossa base,
        // mais nova primeiro.
        if ($canal === PlataformaDeAnuncio::META) {
            $extras['historico'] = MetaAdsCampanhaHistorico::query()
                ->orderByDesc('inicio')
                ->orderByDesc('gasto')
                ->get()
                ->map(fn (MetaAdsCampanhaHistorico $c) => Arr::only($c->toArray(), [
                    'id', 'meta_id', 'nome', 'status_final', 'objetivo', 'orcamento_nivel', 'orcamento_diario',
                    'inicio', 'fim', 'excluida_em', 'gasto', 'impressoes', 'alcance', 'cliques_link', 'ctr_link',
                    'carrinho', 'checkout', 'compras', 'vendas', 'roas', 'conjuntos', 'anuncios',
                ]))
                ->all();
        }

        return Inertia::render('Admin/Publicidade/Plataforma', $extras + [
            'plataforma' => PlataformaDeAnuncio::dados($canal),
            'daMeta' => $metaConectada && $ultimaSincronizacao === null,
            // Lira 2026-10-27: a Meta está conectada pela API (MetaAdsService)
            // mesmo sem gasto diário gravado em channel_ad_spends; antes a
            // tela dizia "Não conectada" por falta dessas linhas.
            'conectado' => $metaConectada || $ultimaSincronizacao !== null,
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

    /** @return array<string, mixed> */
    private function diagnosticoMeta(): array
    {
        $meta = app(MetaAdsService::class);

        try {
            return $meta->diagnosticar($meta->espelhoCompleto(self::PERIODO_DIAGNOSTICO), self::PERIODO_DIAGNOSTICO) + ['erro' => null];
        } catch (MetaAdsException $erro) {
            return ['erro' => $erro->getMessage(), 'cartoes' => [], 'total' => 0];
        }
    }

    private const PERIODO_DIAGNOSTICO = 'last_7d';

    /** @return array{periodos: list<array<string, mixed>>, erro: ?string} */
    private function resumoMeta(Carbon $inicioMes, Carbon $hoje, Carbon $inicioAnterior, Carbon $fimAnterior): array
    {
        $meta = app(MetaAdsService::class);

        try {
            return ['erro' => null, 'periodos' => [
                ['titulo' => 'Mês atual', 'desde' => $inicioMes->toDateString(), 'ate' => $hoje->toDateString(), 'totais' => $meta->totaisDaConta('this_month')],
                ['titulo' => 'Mês anterior', 'desde' => $inicioAnterior->toDateString(), 'ate' => $fimAnterior->toDateString(), 'totais' => $meta->totaisDaConta('last_month')],
                ['titulo' => 'Desde o início', 'desde' => null, 'ate' => $hoje->toDateString(), 'totais' => $meta->totaisDaConta('maximum')],
            ]];
        } catch (MetaAdsException $erro) {
            return ['erro' => $erro->getMessage(), 'periodos' => []];
        }
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
