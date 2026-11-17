<?php

namespace App\Modules\Admin\Support\Indicadores;

use App\Models\User;
use App\Modules\Admin\Support\Publicidade\PlataformaDeAnuncio;
use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelAdSpend;
use App\Modules\Marketplace\Support\ContributionMargin;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Série dia a dia dos Indicadores (pedido do Lira, 2026-10-14: tela só com
 * cards e gráficos, filtro instantâneo). Vai inteira para a tela, que soma
 * qualquer período e qualquer combinação de canais sem voltar ao servidor.
 *
 * Mesmas bases do PainelDeAlertas: receita e pedidos de
 * ContributionMargin::porCanal() (receitaSql, status de receita, sem
 * devolução), cancelados de vendaEfetiva(), ADS de channel_ad_spends e
 * visitante pela regra do painel (sem equipe e sem robô). Dias completos,
 * hoje fica de fora.
 */
class SerieDiaria
{
    public const DIAS = 60;

    /**
     * $grupo (pedido do Lira, 17/11/2026: "marketplaces de um lado, meus
     * e-commerces do outro, nunca somar os dois"): lista de canais do grupo
     * (GruposDeCanais::canais()); a série só traz esses canais, então o
     * "Todos os canais" da tela é a soma do grupo. O ADS da Meta entra no
     * kazakora.com, como em ContributionMargin::porCanal(). Visitantes são
     * os do site kazakora.com: só vêm quando a loja está no grupo (zerados
     * nos marketplaces). Null = todos os canais (comportamento antigo).
     *
     * @param  list<string>|null  $grupo
     * @return array{dias: list<string>, canais: array<string, array<string, list<float|int>>>, visitantes: list<int>}
     */
    public function gerar(?array $grupo = null): array
    {
        $ate = Carbon::today();
        $desde = $ate->copy()->subDays(self::DIAS);
        $dias = [];
        for ($d = $desde->copy(); $d->lt($ate); $d->addDay()) {
            $dias[] = $d->toDateString();
        }
        $canais = $grupo ?? array_keys(PainelDeAlertas::CANAIS);
        $vazio = array_fill(0, count($dias), 0);
        $pos = array_flip($dias);
        $serie = [];
        $por = function (string $canal, string $campo, string $dia, float|int $valor, bool $somar = false) use (&$serie, $pos, $vazio) {
            $dia = substr($dia, 0, 10);
            if (! isset($pos[$dia])) {
                return;
            }
            $serie[$canal] ??= [];
            $serie[$canal][$campo] ??= $vazio;
            // Somar: Meta + kazakora.com no mesmo dia; inteiro continua inteiro.
            $atual = $serie[$canal][$campo][$pos[$dia]];
            $serie[$canal][$campo][$pos[$dia]] = $somar ? (is_float($valor) ? round($atual + $valor, 2) : $atual + $valor) : $valor;
        };

        DB::table('orders')
            ->whereIn('orders.status', ContributionMargin::REVENUE_STATUSES)
            ->whereIn(DB::raw(Order::canalSql()), $canais)
            ->where('orders.created_at', '>=', $desde)->where('orders.created_at', '<', $ate)
            ->tap(fn ($q) => ContributionMargin::semDevolucao($q))
            ->selectRaw(Order::canalSql().' as canal, DATE(orders.created_at) as dia, COUNT(*) as pedidos, COALESCE(SUM('.ContributionMargin::receitaSql().'), 0) as receita')
            ->groupBy('canal', 'dia')
            ->get()
            ->each(function ($l) use ($por) {
                $por($l->canal, 'pedidos', (string) $l->dia, (int) $l->pedidos);
                $por($l->canal, 'receita', (string) $l->dia, round((float) $l->receita, 2));
            });

        Order::query()->vendaEfetiva()
            ->whereIn(DB::raw(Order::canalSql()), $canais)
            ->where('created_at', '>=', $desde)->where('created_at', '<', $ate)
            ->selectRaw(Order::canalSql()." as canal, DATE(orders.created_at) as dia, COUNT(*) as total, SUM(CASE WHEN orders.status = 'cancelled' THEN 1 ELSE 0 END) as cancelados")
            ->groupBy('canal', 'dia')
            ->toBase()->get()
            ->each(function ($l) use ($por) {
                $por($l->canal, 'todos', (string) $l->dia, (int) $l->total);
                $por($l->canal, 'cancelados', (string) $l->dia, (int) $l->cancelados);
            });

        // Grupo (17/11/2026): a Meta é o ADS do kazakora.com; sem grupo a
        // série fica como era (a tela não mostra a chave da Meta).
        $comMeta = $grupo !== null && in_array(Order::ORIGIN_STORE, $grupo, true);
        ChannelAdSpend::query()
            ->where('date', '>=', $desde->toDateString())->where('date', '<', $ate->toDateString())
            ->when($grupo !== null, fn ($q) => $q->whereIn('channel', $comMeta ? [...$grupo, PlataformaDeAnuncio::META] : $grupo))
            ->selectRaw('channel, date, SUM(impressions) as impressoes, SUM(clicks) as cliques, SUM(attributed_orders) as pedidos, SUM(attributed_gmv) as venda, SUM(spend) as gasto')
            ->groupBy('channel', 'date')
            ->toBase()->get()
            ->each(function ($l) use ($por, $comMeta) {
                $dia = Carbon::parse($l->date)->toDateString();
                $canal = $comMeta && $l->channel === PlataformaDeAnuncio::META ? Order::ORIGIN_STORE : $l->channel;
                $por($canal, 'ads_gasto', $dia, round((float) $l->gasto, 2), true);
                $por($canal, 'ads_venda', $dia, round((float) $l->venda, 2), true);
                $por($canal, 'ads_pedidos', $dia, (int) $l->pedidos, true);
                $por($canal, 'impressoes', $dia, (int) $l->impressoes, true);
                $por($canal, 'cliques', $dia, (int) $l->cliques, true);
            });

        $visitantes = $vazio;
        if ($grupo !== null && ! in_array(Order::ORIGIN_STORE, $grupo, true)) {
            return ['dias' => $dias, 'canais' => $serie, 'visitantes' => $visitantes];
        }
        $equipe = User::query()->whereIn('role', User::STAFF_ROLES)->pluck('id')->map(fn ($id) => (int) $id)->all();
        SiteVisit::soDeFora(SiteVisit::visitantesPorDia(
            SiteVisit::query()->daLoja()->where('created_at', '>=', $desde)->where('created_at', '<', $ate),
            $equipe,
        ))->groupBy('dia')->each(function ($doDia, $dia) use (&$visitantes, $pos) {
            if (isset($pos[$dia])) {
                $visitantes[$pos[$dia]] = $doDia->pluck('visitante')->unique()->count();
            }
        });

        return ['dias' => $dias, 'canais' => $serie, 'visitantes' => $visitantes];
    }
}
