<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Support\Indicadores\PainelDeAlertas;
use App\Modules\Admin\Support\Indicadores\PainelGerencial;
use App\Modules\Admin\Support\Indicadores\SerieDiaria;
use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Financeiro\Support\GruposDeCanais;
use App\Modules\Marketplace\Support\ContributionMargin;
use App\Support\Rbac\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class KpiController extends Controller
{
    /**
     * Abas (2026-10-19): o que era o Gerenciador veio para cá quando /admin
     * virou o Fluxo de Caixa. Cada aba manda só as próprias props; trocar
     * de aba é uma visita nova com ?aba= e calcula só aquela aba.
     *
     * 'acessos' é a aba Visitas KazaKora (a chave antiga fica pelos links
     * salvos) e 'visitas-ferrakora' a Visitas FerraKora (pedido 2026-10-22).
     */
    public const ABAS = ['visao-geral', 'hoje-e-mes', 'caue-diego', 'produtos', 'acessos', 'visitas-ferrakora'];

    /**
     * Abas de cada grupo (pedido do Lira, 17/11/2026: "métricas somadas só
     * dos marketplaces de um lado; do outro, em outra tela, meus
     * e-commerces; nunca somar os dois grupos em nenhum lugar"). Cauê e
     * Diego é diagnóstico da Shopee e do Mercado Livre; as visitas são dos
     * sites próprios.
     */
    public const ABAS_POR_GRUPO = [
        'marketplaces' => ['visao-geral', 'hoje-e-mes', 'caue-diego', 'produtos'],
        'ecommerces' => ['visao-geral', 'hoje-e-mes', 'produtos', 'acessos', 'visitas-ferrakora'],
    ];

    /**
     * /admin/indicadores (endereço antigo, 17/11/2026): leva ao grupo do
     * ?canal= (marketplaces sem canal); as abas de visitas são dos
     * e-commerces. A query string vai junto.
     */
    public function antigo(Request $request): RedirectResponse
    {
        $aba = (string) $request->query('aba', '');
        $slug = in_array($aba, ['acessos', 'visitas-ferrakora'], true)
            ? 'ecommerces'
            : GruposDeCanais::slugDoCanal($request->query('canal') ? (string) $request->query('canal') : null);
        $query = http_build_query($request->query());

        return redirect()->to("/admin/painel/{$slug}/indicadores".($query !== '' ? '?'.$query : ''));
    }

    public function index(Request $request, PainelDeAlertas $painel, SerieDiaria $serie, ContributionMargin $margem, PainelGerencial $gerencial, string $grupo = 'marketplaces'): Response
    {
        $grupo = isset(self::ABAS_POR_GRUPO[$grupo]) ? $grupo : 'marketplaces';
        $canais = GruposDeCanais::canais($grupo);
        $abas = self::ABAS_POR_GRUPO[$grupo];
        $aba = (string) $request->query('aba', 'visao-geral');
        // Aba de fora do grupo (ex.: Cauê e Diego nos e-commerces) cai na visão geral.
        $aba = in_array($aba, $abas, true) ? $aba : 'visao-geral';

        $props = match ($aba) {
            'hoje-e-mes' => ['hojeMes' => fn () => $gerencial->hojeEMes(Permissions::has($request->user(), 'financeiro.view'), $canais)],
            'caue-diego' => ['diagnosticos' => fn () => $gerencial->diagnosticos()],
            'produtos' => ['produtos' => fn () => $gerencial->produtos($canais)],
            'acessos' => ['visitantes' => fn () => $gerencial->visitantes((string) $request->query('mes', ''))],
            'visitas-ferrakora' => ['visitantes' => fn () => $gerencial->visitantes((string) $request->query('mes', ''), 'ferrakora')],
            default => $this->visaoGeral($request, $painel, $serie, $margem, $grupo, $canais),
        };

        return Inertia::render('Admin/Indicadores/Index', ['aba' => $aba, 'grupo' => $grupo, 'abas' => $abas, 'canais' => $canais] + $props);
    }

    /**
     * Aba "Visão geral": o conteúdo de Indicadores de antes, só com os
     * canais do grupo (17/11/2026). Conversão e visitantes são do funil do
     * site: só existem nos e-commerces (nulos nos marketplaces).
     *
     * @param  list<string>  $canais
     */
    private function visaoGeral(Request $request, PainelDeAlertas $painel, SerieDiaria $serie, ContributionMargin $margem, string $grupo, array $canais): array
    {
        $startOfMonth = Carbon::today()->startOfMonth();

        $ordersMonth = Order::query()->doCanal($canais)->where('created_at', '>=', $startOfMonth);
        $totalOrdersMonth = (clone $ordersMonth)->count();
        $cancelledOrdersMonth = (clone $ordersMonth)->where('status', Order::STATUS_CANCELLED)->count();
        $validOrders = Order::query()->doCanal($canais)->whereNot('status', Order::STATUS_CANCELLED);
        $validOrdersMonth = (clone $ordersMonth)->whereNot('status', Order::STATUS_CANCELLED);

        // BUG REAL 2026-08-17 ("as métricas do kazakora ainda têm
        // informação incorreta", achado ao vivo: taxa de conversão
        // mostrando 200%): conversionRate comparava TODO pedido válido do
        // mês (qualquer canal) contra uniqueVisitorsMonth, que só existe
        // pra visita real na loja própria (SiteVisit não rastreia
        // marketplace nenhum). No mês corrente, 100% dos 227 pedidos
        // vieram de Shopee/ML/TikTok/Amazon — zero da loja própria —
        // então "conversão" comparava dois funis sem relação nenhuma
        // (comprador que nunca visitou o site vindo de outro canal). Taxa
        // de conversão só faz sentido pro funil visita->compra da PRÓPRIA
        // loja (ORIGIN_STORE); pedido de marketplace não entra nem no
        // numerador nem no denominador dessa conta.
        // Só kazakora.com (2026-10-14): pedido do FerraKora/Beauty Kora não
        // conta contra os visitantes da loja.
        $storeOrdersMonth = (clone $validOrdersMonth)->doCanal(Order::ORIGIN_STORE);

        // subtotal, não total — 'total' inclui frete (não é receita do
        // vendedor), ver comentário em FinancialDashboardController::index().
        $averageTicket = (clone $validOrders)->count() > 0
            ? (float) (clone $validOrders)->sum('subtotal') / (clone $validOrders)->count()
            : 0.0;

        // Visitante único = cookie kazakora_visitor; sem OK de cookies, um
        // por IP no dia (pedido 2026-10-11, mesma conta do
        // DashboardController). Refresh/navegação do mesmo visitante não
        // conta de novo.
        // Marketplaces (17/11/2026): não há visita de site no funil, então
        // visitantes e conversão vão nulos e a tela não mostra.
        $temSite = in_array(Order::ORIGIN_STORE, $canais, true);
        $uniqueVisitorsMonth = $temSite ? SiteVisit::visitantesPorDia(
            SiteVisit::query()->daLoja()->where('created_at', '>=', $startOfMonth)
        )->pluck('visitante')->unique()->count() : null;

        $conversionRate = ! $temSite ? null : ($uniqueVisitorsMonth > 0
            ? ($storeOrdersMonth->count() / $uniqueVisitorsMonth) * 100
            : 0.0);

        $productsCount = Product::query()->count();
        $lowStockCount = Product::query()->where('stock', '<=', 5)->count();

        // Indicadores com alerta por canal (2026-10-14): últimos N dias
        // completos contra os N anteriores, ver PainelDeAlertas.
        // Tela só de cards e gráficos (2026-10-14): os 3 períodos, a
        // composição da receita (conta oficial) e a série diária vão juntos,
        // e o filtro de período/canal troca na tela sem voltar ao servidor.
        // Dias completos: 10 min de cache não muda nada além dos alertas de
        // operação.
        $dias = (int) $request->integer('dias', 7);
        $dias = in_array($dias, [7, 14, 30], true) ? $dias : 7;
        // Grupo na chave do cache (17/11/2026): cada grupo tem os seus números.
        $dados = Cache::remember('indicadores:graficos:'.$grupo.':'.Carbon::today()->toDateString(), now()->addMinutes(10), function () use ($painel, $serie, $margem, $canais) {
            $paineis = [];
            $composicao = [];
            foreach ([7, 14, 30] as $d) {
                $paineis[$d] = $painel->gerar($d, $canais);
                // Só as linhas dos canais do grupo: a tela soma estas.
                $composicao[$d] = array_intersect_key($margem->porCanal(Carbon::today()->subDays($d), Carbon::today()), array_flip($canais));
            }

            return ['paineis' => $paineis, 'composicao' => $composicao, 'serie' => $serie->gerar($canais)];
        });

        return [
            'painel' => $dados['paineis'][$dias],
            'paineis' => $dados['paineis'],
            'composicao' => $dados['composicao'],
            'serie' => $dados['serie'],
            'kpis' => [
                'averageTicket' => $averageTicket,
                'conversionRate' => $conversionRate === null ? null : round($conversionRate, 2),
                'cancellationRate' => $totalOrdersMonth > 0 ? round(($cancelledOrdersMonth / $totalOrdersMonth) * 100, 2) : 0.0,
                'lowStockRate' => $productsCount > 0 ? round(($lowStockCount / $productsCount) * 100, 2) : 0.0,
                'uniqueVisitorsMonth' => $uniqueVisitorsMonth,
                'ordersMonth' => $totalOrdersMonth,
            ],
        ];
    }
}
