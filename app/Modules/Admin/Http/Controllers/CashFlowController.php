<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cadastros\Models\CostCenter;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Financeiro\Models\CashFlowEntry;
use App\Modules\Financeiro\Support\AlertasFinanceiros;
use App\Modules\Financeiro\Support\GruposDeCanais;
use App\Modules\Financeiro\Support\PrevisaoDeCaixa;
use App\Modules\Marketplace\Models\OrderChannelFee;
use App\Modules\Marketplace\Support\ContributionMargin;
use App\Modules\Marketplace\Support\LucroPorPedido;
use App\Support\Http\TableSort;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Fluxo de Caixa (nova tela aprovada pelo Lira em 2026-10-19, proposta em
 * Documents/2026-10-19-proposta-fluxo-de-caixa.md). Três abas:
 *
 * - Resumo: alertas, KPIs, cascata, lucro por canal, evolução, próximos 30
 *   dias e confiabilidade. Tudo pela conta oficial (ContributionMargin).
 * - Vendas (custo e comissão): a tabela item a item, agora pela mesma conta
 *   (ContributionMargin::porItem); antes fazia conta própria e dava margem
 *   4 vezes maior que a oficial.
 * - Lançamentos: entradas e saídas digitadas à mão, filtradas pelo período.
 *
 * Só a aba aberta é calculada: cada aba manda as próprias props.
 *
 * Marketplaces x E-commerces (pedido do Lira em 17/11/2026: "separa tudo,
 * métricas somadas só dos marketplaces de um lado, meus e-commerces do
 * outro"): a tela é sempre de UM grupo (/admin/painel/marketplaces ou
 * /admin/painel/ecommerces, ver GruposDeCanais). Nenhum número soma os dois
 * grupos; o filtro de canal só oferece canais do grupo aberto.
 */
class CashFlowController extends Controller
{
    private const PER_PAGE = 50;

    private const ABAS = ['resumo', 'vendas', 'lancamentos'];

    /** Canais do filtro (FerraKora e Beauty Kora só aparecem com venda). */
    public const CANAIS = [
        Order::ORIGIN_STORE => 'KazaKora',
        Order::ORIGIN_SHOPEE => 'Shopee',
        Order::ORIGIN_MERCADO_LIVRE => 'Mercado Livre',
        Order::ORIGIN_TIKTOK_SHOP => 'TikTok Shop',
        Order::ORIGIN_AMAZON => 'Amazon',
        Order::SITE_FERRAKORA => 'FerraKora',
        Order::SITE_BEAUTYKORA => 'Beauty Kora',
        'shein' => 'Shein',
    ];

    private const CANAIS_FIXOS = [Order::ORIGIN_TIKTOK_SHOP, Order::ORIGIN_SHOPEE, Order::ORIGIN_MERCADO_LIVRE, Order::ORIGIN_AMAZON, Order::ORIGIN_STORE];

    public function __construct(private ContributionMargin $margem) {}

    /** Endereço antigo (/admin/fluxo-de-caixa): vai para o painel do grupo do canal filtrado. */
    public function antigo(Request $request): \Illuminate\Http\RedirectResponse
    {
        $canal = (string) ($request->query('canal') ?? $request->query('platform') ?? '');
        $query = (string) $request->server('QUERY_STRING');

        return redirect()->to('/admin/painel/'.GruposDeCanais::slugDoCanal($canal ?: null).'/resultado'.($query ? '?'.$query : ''));
    }

    public function index(Request $request, string $grupo = 'marketplaces'): Response
    {
        $filtro = $this->filtro($request, $grupo);
        $aba = $filtro['aba'];

        $props = [
            'filtro' => $filtro['publico'],
            'canaisFiltro' => fn () => $this->canaisDoFiltro($filtro),
            'costCenters' => fn () => CostCenter::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ];

        if ($aba === 'resumo') {
            $props += $this->propsResumo($filtro);
        } elseif ($aba === 'vendas') {
            $props += $this->propsVendas($request, $filtro);
        } else {
            $props += $this->propsLancamentos($request, $filtro);
        }

        return Inertia::render('Admin/CashFlow/Index', $props);
    }

    // ------------------------------------------------------------------
    // Filtros
    // ------------------------------------------------------------------

    /**
     * Período em America/Sao_Paulo, intervalo [de, até + 1 dia). Aceita os
     * nomes antigos da aba de vendas (start/end/platform) para não quebrar
     * link salvo.
     *
     * @return array<string, mixed>
     */
    private function filtro(Request $request, string $grupo): array
    {
        $grupo = array_key_exists($grupo, GruposDeCanais::SLUGS) ? $grupo : 'marketplaces';
        $canaisGrupo = GruposDeCanais::canais($grupo);

        $hoje = Carbon::today();
        $data = function (?string $valor): ?Carbon {
            if ($valor === null || $valor === '') {
                return null;
            }
            try {
                return Carbon::createFromFormat('!Y-m-d', substr($valor, 0, 10));
            } catch (\Throwable) {
                return null;
            }
        };

        $de = $data($request->query('de') ?? $request->query('start')) ?? $hoje->copy()->startOfMonth();
        $ate = $data($request->query('ate') ?? $request->query('end')) ?? $hoje->copy();
        if ($de->gt($ate)) {
            [$de, $ate] = [$ate, $de];
        }
        $fim = $ate->copy()->addDay();

        $canal = (string) ($request->query('canal') ?? $request->query('platform') ?? '');
        $canal = array_key_exists($canal, self::CANAIS) && in_array($canal, $canaisGrupo, true) ? $canal : null;

        $comparar = (string) $request->query('comparar', 'mes_passado');
        $comparar = in_array($comparar, ['mes_passado', 'anterior', 'nenhum'], true) ? $comparar : 'mes_passado';

        $dias = (int) $de->diffInDays($ate) + 1;
        $agrupar = (string) $request->query('agrupar', 'auto');
        $agruparFixo = in_array($agrupar, ['dia', 'semana', 'mes'], true);
        if (! $agruparFixo) {
            $agrupar = $dias <= 31 ? 'dia' : ($dias <= 120 ? 'semana' : 'mes');
        }
        // Cada balde é uma conta inteira: dia a dia num ano seriam 365.
        if ($agrupar === 'dia' && $dias > 92) {
            $agrupar = 'semana';
        }
        if ($agrupar === 'semana' && $dias > 400) {
            $agrupar = 'mes';
        }

        $aba = (string) $request->query('aba', 'resumo');
        $aba = in_array($aba, self::ABAS, true) ? $aba : 'resumo';

        [$compDe, $compAte, $compRotulo] = match ($comparar) {
            'mes_passado' => [$de->copy()->subMonthNoOverflow(), $ate->copy()->subMonthNoOverflow(), 'mesmo período do mês passado'],
            'anterior' => [$de->copy()->subDays($dias), $de->copy()->subDay(), 'período anterior'],
            default => [null, null, null],
        };

        return [
            'grupo' => $grupo,
            'canaisGrupo' => $canaisGrupo,
            // O que entra na conta: o canal escolhido ou o grupo inteiro (lista).
            'escopo' => $canal ?? $canaisGrupo,
            'urlBase' => '/admin/painel/'.$grupo.'/resultado',
            'aba' => $aba,
            'de' => $de,
            'ate' => $ate,
            'fim' => $fim,
            'canal' => $canal,
            'comparar' => $comparar,
            'agrupar' => $agrupar,
            'dias' => $dias,
            'compDe' => $compDe,
            'compFim' => $compAte?->copy()->addDay(),
            'compRotulo' => $compRotulo ? $compRotulo.' ('.$compDe->format('d/m').' a '.$compAte->format('d/m').')' : null,
            'publico' => [
                'grupo' => $grupo,
                'grupoNome' => GruposDeCanais::NOMES[GruposDeCanais::SLUGS[$grupo]],
                'grupoDescricao' => GruposDeCanais::DESCRICOES[GruposDeCanais::SLUGS[$grupo]],
                'urlBase' => '/admin/painel/'.$grupo.'/resultado',
                'aba' => $aba,
                'de' => $de->toDateString(),
                'ate' => $ate->toDateString(),
                'canal' => $canal,
                'comparar' => $comparar,
                'agrupar' => $agrupar,
                'agruparFixo' => $agruparFixo,
                'hoje' => $hoje->toDateString(),
                'compDe' => $compDe?->toDateString(),
                'compAte' => $compAte?->toDateString(),
                'rotuloComparacao' => $compRotulo,
            ],
        ];
    }

    /** @return list<array{value: string, label: string}> */
    private function canaisDoFiltro(array $f): array
    {
        $comVenda = DB::table('orders')
            ->whereIn('orders.status', ContributionMargin::REVENUE_STATUSES)
            ->where('orders.created_at', '>=', $f['de'])
            ->where('orders.created_at', '<', $f['fim'])
            ->tap(fn ($q) => ContributionMargin::semDevolucao($q))
            ->selectRaw('DISTINCT '.Order::canalSql().' as canal')
            ->pluck('canal')
            ->all();

        return collect(array_unique([...self::CANAIS_FIXOS, ...$comVenda, ...array_filter([$f['canal']])]))
            ->filter(fn ($c) => isset(self::CANAIS[$c]) && in_array($c, $f['canaisGrupo'], true))
            ->map(fn ($c) => ['value' => $c, 'label' => self::CANAIS[$c]])
            ->values()
            ->all();
    }

    private function rotuloPeriodo(Carbon $de, Carbon $ate): string
    {
        if ($de->isSameMonth($ate) && $de->day === 1 && ($ate->isSameDay($ate->copy()->endOfMonth()) || $ate->isToday())) {
            return $de->copy()->locale('pt_BR')->translatedFormat('F');
        }

        return $de->format('d/m').' a '.$ate->format('d/m');
    }

    // ------------------------------------------------------------------
    // Aba Resumo
    // ------------------------------------------------------------------

    /** @return array<string, \Closure> */
    private function propsResumo(array $f): array
    {
        $canal = $f['canal'];
        $escopo = $f['escopo'];
        $soEscopo = fn (array $porCanal) => array_intersect_key($porCanal, array_flip((array) $escopo));
        $memo = [];
        $uma = function (string $chave, \Closure $calc) use (&$memo) {
            return $memo[$chave] ??= $calc();
        };

        $porCanal = fn () => $uma('porCanal', fn () => $this->margem->porCanal($f['de'], $f['fim']));
        $qualidade = fn () => $uma('qualidade', fn () => ContributionMargin::qualidadeTaxas($f['de'], $f['fim']));
        $resumo = fn () => $uma('resumo', function () use ($f, $escopo, $porCanal) {
            $atual = $this->numeros($f['de'], $f['fim'], $escopo, $porCanal());
            $comparacao = $f['compDe'] ? $this->numeros($f['compDe'], $f['compFim'], $escopo) : null;

            return ['atual' => $atual, 'comparacao' => $comparacao, 'aliquota' => ContributionMargin::aliquotaSimples(),
                'adsMeta' => (float) config('loja.ads_meta_percentual', 4), 'adsLimite' => (float) config('loja.ads_alerta_percentual', 6)];
        });
        $pendencias = fn () => $uma('pendencias', fn () => $this->pendencias($f['de'], $f['fim'], $escopo));
        $das = fn () => $uma('das', fn () => app(PrevisaoDeCaixa::class)->das());

        $props = [
            'resumo' => $resumo,
            'canais' => function () use ($porCanal, $qualidade, $canal, $soEscopo, $f) {
                $linhas = $soEscopo($porCanal());
                if ($canal === null) {
                    foreach (array_intersect(self::CANAIS_FIXOS, $f['canaisGrupo']) as $fixo) {
                        $linhas[$fixo] ??= ContributionMargin::contaVazia();
                    }
                } else {
                    $linhas = [$canal => $linhas[$canal] ?? ContributionMargin::contaVazia()];
                }
                $q = $qualidade();

                return collect($linhas)
                    ->filter(fn ($l, $c) => isset(self::CANAIS[$c]) || $l['receita'] != 0)
                    ->map(fn ($l, $c) => ['canal' => $c, 'nome' => self::CANAIS[$c] ?? $c] + $l + [
                        'taxa_estimada' => (float) ($q[$c]['estimada'] ?? 0),
                    ])
                    ->sortByDesc('lucro')
                    ->values()
                    ->all();
            },
            'serie' => function () use ($f, $escopo) {
                $atual = $this->margem->serie($f['de'], $f['fim'], $f['agrupar'], $escopo);
                $anterior = $f['compDe'] ? $this->margem->serie($f['compDe'], $f['compFim'], $f['agrupar'], $escopo) : [];

                return collect($atual)->values()->map(fn ($b, $i) => [
                    'inicio' => $b['inicio'],
                    'fim' => $b['fim'],
                    'receita' => (float) $b['receita'],
                    'margem' => (float) $b['margem'],
                    'lucro' => (float) $b['lucro'],
                    'ads' => (float) $b['ads'],
                    'comparacao' => isset($anterior[$i]) ? (float) $anterior[$i]['receita'] : null,
                    'comparacaoInicio' => $anterior[$i]['inicio'] ?? null,
                ])->all();
            },
            // Carteiras e repasses são dos marketplaces: os próximos dias só
            // aparecem no painel de Marketplaces.
            'previsao' => fn () => $f['grupo'] === 'marketplaces' ? app(PrevisaoDeCaixa::class)->proximosDias() : null,
            'qualidade' => function () use ($qualidade, $pendencias, $soEscopo) {
                $q = $soEscopo($qualidade());

                return [
                    'canais' => collect($q)->map(fn ($l, $c) => ['canal' => $c, 'nome' => self::CANAIS[$c] ?? $c] + $l)->values()->all(),
                    'pendencias' => $pendencias(),
                ];
            },
            'alertas' => function () use ($f, $canal, $porCanal, $qualidade, $pendencias, $resumo, $das, $soEscopo) {
                $r = $resumo();
                $linhas = $soEscopo($porCanal());
                $q = $soEscopo($qualidade());
                $p = $pendencias();

                return app(AlertasFinanceiros::class)->gerar([
                    'hoje' => Carbon::today(),
                    'rotuloPeriodo' => $this->rotuloPeriodo($f['de'], $f['ate']),
                    'das' => $das(),
                    'canais' => $linhas,
                    'receita' => (float) $r['atual']['receita'],
                    'receitaComparacao' => $r['comparacao'] !== null ? (float) $r['comparacao']['receita'] : null,
                    'rotuloComparacao' => $f['compRotulo'],
                    'qualidade' => $q,
                    'semCusto' => $p['semCusto'],
                    'linkSemCusto' => $f['urlBase'].'?'.http_build_query(array_filter([
                        'aba' => 'vendas', 'sem_custo' => 1, 'de' => $f['de']->toDateString(), 'ate' => $f['ate']->toDateString(), 'canal' => $canal,
                    ])),
                    'notasCanceladas' => $p['notasCanceladas'],
                ]);
            },
        ];

        // Últimos pedidos dos sites com o lucro líquido de cada um (pedido do
        // Lira em 25/11/2026). Só no painel de E-commerces: marketplace tem
        // a aba Vendas.
        if ($f['grupo'] === 'ecommerces') {
            $props['ultimosPedidos'] = fn () => [
                'pedidos' => app(LucroPorPedido::class)->ultimos($f['de'], $f['fim'], (array) $escopo),
                'verTodos' => '/admin/pedidos?'.http_build_query(array_filter([
                    'marketplace' => $canal,
                    'grupo' => $canal ? null : $f['grupo'],
                    'de' => $f['de']->toDateString(),
                    'ate' => $f['ate']->toDateString(),
                ])),
            ];
        }

        return $props;
    }

    /**
     * Números dos cartões: a conta oficial + vendas brutas, faturamento em
     * nota e pedidos.
     *
     * @return array<string, float|int>
     */
    private function numeros(Carbon $desde, Carbon $fim, string|array $escopo, ?array $porCanal = null): array
    {
        $porCanal ??= $this->margem->porCanal($desde, $fim);
        $conta = $this->margem->conta($desde, $fim, $escopo);
        $base = ContributionMargin::baseImposto($desde, $fim);
        $canais = array_flip((array) $escopo);
        $pedidos = (int) array_sum(array_column(array_intersect_key($porCanal, $canais), 'pedidos'));

        return [
            'receita' => (float) $conta['receita'],
            'custo_produtos' => (float) $conta['custo_produtos'],
            'taxas' => (float) $conta['taxas'],
            'frete' => (float) $conta['frete'],
            'insumos' => (float) ($conta['insumos'] ?? 0),
            'insumos_embalagem' => (float) ($conta['insumos_embalagem'] ?? 0),
            'insumos_lancados' => (float) ($conta['insumos_lancados'] ?? 0),
            'ads' => (float) $conta['ads'],
            'bonificacoes' => (float) $conta['bonificacoes'],
            'comissao_zaio' => (float) ($conta['comissao_zaio'] ?? 0),
            'margem' => (float) $conta['margem'],
            'imposto' => (float) $conta['imposto'],
            'lucro' => (float) $conta['lucro'],
            'pedidos' => $pedidos,
            'vendas_brutas' => ContributionMargin::vendasBrutas($desde, $fim, $escopo),
            'faturamento_nota' => round(array_sum(array_intersect_key($base, $canais)), 2),
            'pedidos_site' => isset($canais[Order::ORIGIN_STORE]) ? (int) ($porCanal[Order::ORIGIN_STORE]['pedidos'] ?? 0) : null,
        ];
    }

    /**
     * Itens sem custo, notas autorizadas de pedido cancelado e vendas sem
     * nota no período (bloco 7).
     *
     * @return array{semCusto: int, notasCanceladas: array{quantidade: int, valor: float}, semNota: int}
     */
    private function pendencias(Carbon $desde, Carbon $fim, string|array $canal): array
    {
        $vendas = fn () => DB::table('orders')
            ->whereIn('orders.status', ContributionMargin::REVENUE_STATUSES)
            ->where('orders.created_at', '>=', $desde)
            ->where('orders.created_at', '<', $fim)
            ->when($canal, fn ($q) => Order::filtrarCanal($q, $canal))
            ->tap(fn ($q) => ContributionMargin::semDevolucao($q));

        $semCusto = (int) $vendas()
            ->join('order_items', 'order_items.order_id', '=', 'orders.id')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->whereNull('products.cost_price')
            ->whereNull('order_items.manual_cost_price')
            ->count();

        $notas = DB::table('invoices')
            ->join('orders', 'orders.id', '=', 'invoices.order_id')
            ->where('orders.status', Order::STATUS_CANCELLED)
            ->where('orders.created_at', '>=', $desde)
            ->where('orders.created_at', '<', $fim)
            ->when($canal, fn ($q) => Order::filtrarCanal($q, $canal))
            ->where('invoices.status', 'authorized')
            ->where('invoices.operation_type', 'saida')
            ->whereNull('invoices.cancelada_em')
            ->selectRaw('COUNT(*) as n, COALESCE(SUM(invoices.valor_total), 0) as valor')
            ->first();

        $semNota = (int) $vendas()
            ->whereNotExists(fn ($q) => $q->from('invoices')
                ->whereColumn('invoices.order_id', 'orders.id')
                ->where('invoices.status', 'authorized')
                ->where('invoices.operation_type', 'saida')
                ->whereNull('invoices.cancelada_em'))
            ->count();

        return [
            'semCusto' => $semCusto,
            'notasCanceladas' => ['quantidade' => (int) ($notas->n ?? 0), 'valor' => round((float) ($notas->valor ?? 0), 2)],
            'semNota' => $semNota,
        ];
    }

    // ------------------------------------------------------------------
    // Aba Vendas (custo e comissão)
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function propsVendas(Request $request, array $f): array
    {
        $search = $request->string('search')->toString();
        $semCusto = $request->boolean('sem_custo');
        $taxaEstimada = $request->boolean('taxa_estimada');
        $sort = TableSort::resolve($request, [
            'date' => 'pedido_em',
            'product_name' => 'product_name',
            'platform' => 'canal',
            'receita' => 'receita',
            'product_cost' => 'custo_produtos',
            'platform_fee' => 'taxas',
            'shipping_cost' => 'frete',
            'supplies_cost' => 'insumos',
            'net_profit' => 'margem',
        ], 'pedido_em');

        $memo = null;
        $dados = function () use (&$memo, $f, $search, $semCusto, $taxaEstimada, $sort) {
            if ($memo !== null) {
                return $memo;
            }
            $conta = $this->margem->porItem($f['de'], $f['fim'], $f['escopo']);
            $todas = collect($conta['linhas']);

            $termo = mb_strtolower(trim($search));
            $linhas = $todas
                ->when($termo !== '', fn ($c) => $c->filter(fn ($l) => str_contains(mb_strtolower($l['product_name']), $termo)
                    || str_contains((string) $l['order_id'], $termo)
                    || str_contains(mb_strtolower(self::CANAIS[$l['canal']] ?? $l['canal']), $termo)))
                ->when($semCusto, fn ($c) => $c->filter(fn ($l) => $l['item_id'] !== null && ! $l['tem_custo']))
                ->when($taxaEstimada, fn ($c) => $c->filter(fn ($l) => $l['taxa_estimada']));

            $coluna = $sort['column'];
            $linhas = $linhas->sort(function ($a, $b) use ($coluna, $sort) {
                $cmp = is_string($a[$coluna]) ? strnatcasecmp($a[$coluna], $b[$coluna]) : $a[$coluna] <=> $b[$coluna];
                $cmp = $sort['direction'] === 'desc' ? -$cmp : $cmp;

                if ($cmp !== 0) {
                    return $cmp;
                }

                return ($b['order_id'] <=> $a['order_id']) ?: (($a['item_id'] ?? 0) <=> ($b['item_id'] ?? 0));
            })->values();

            $filtrado = $termo !== '' || $semCusto || $taxaEstimada;
            $margemLinhas = (float) $linhas->sum('margem');

            return $memo = [
                'linhas' => $linhas,
                'totais' => [
                    'receita' => round((float) $linhas->sum('receita'), 2),
                    'cost' => round((float) $linhas->sum('custo_produtos'), 2),
                    'fee' => round((float) $linhas->sum('taxas'), 2),
                    'shipping' => round((float) $linhas->sum('frete'), 2),
                    'supplies' => round((float) $linhas->sum('insumos'), 2),
                    'netProfit' => round($margemLinhas, 2),
                    'ads' => $conta['canal']['ads'],
                    'flex' => round((float) $conta['canal']['flex'], 2),
                    'bonificacoes' => $conta['canal']['bonificacoes'],
                    'insumosLancados' => $conta['canal']['insumos_lancados'],
                    // Igual à margem do Resumo (periodo/porCanal) quando não
                    // há busca nem filtro de linha.
                    'margemOficial' => $filtrado ? null : round($margemLinhas - $conta['canal']['ads'] - $conta['canal']['flex'] + $conta['canal']['bonificacoes'] - $conta['canal']['cobrancas'] - $conta['canal']['insumos_lancados'], 2),
                    'filtrado' => $filtrado,
                    'semCusto' => $todas->filter(fn ($l) => $l['item_id'] !== null && ! $l['tem_custo'])->count(),
                    'taxaEstimada' => $todas->filter(fn ($l) => $l['taxa_estimada'])->count(),
                ],
            ];
        };

        return [
            'sales' => function () use ($dados, $request) {
                $linhas = $dados()['linhas'];
                $pagina = max(1, (int) $request->query('page', 1));

                return (new LengthAwarePaginator(
                    $linhas->forPage($pagina, self::PER_PAGE)->map(fn ($l) => $this->linhaVenda($l))->values(),
                    $linhas->count(),
                    self::PER_PAGE,
                    $pagina,
                    ['path' => $request->url(), 'query' => $request->query()],
                ));
            },
            'salesTotals' => fn () => $dados()['totais'],
            'salesFilter' => [
                'search' => $search,
                'sem_custo' => $semCusto,
                'taxa_estimada' => $taxaEstimada,
                'sort' => $sort['key'],
                'direction' => $sort['direction'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function linhaVenda(array $l): array
    {
        return [
            'row_key' => $l['order_id'].'-'.($l['item_id'] ?? 0),
            'date' => Carbon::parse($l['pedido_em'])->toDateString(),
            'order_id' => $l['order_id'],
            'item_id' => $l['item_id'],
            'product_name' => $l['product_name'] !== '' ? $l['product_name'] : '(pedido sem itens)',
            'quantity' => $l['quantidade'],
            'canal' => $l['canal'],
            'platform' => self::CANAIS[$l['canal']] ?? $l['canal'],
            'receita' => round($l['receita'], 2),
            'product_cost' => round($l['custo_produtos'], 2),
            'has_cost' => $l['tem_custo'],
            'has_product' => $l['product_id'] !== null,
            'platform_fee' => round($l['taxas'], 2),
            'has_fee_data' => $l['tem_taxa'],
            'fee_source' => $l['tem_taxa'] ? ($l['taxa_estimada'] ? 'estimate' : 'real') : null,
            'shipping_cost' => round($l['frete'], 2),
            // Insumos (embalagem) do pedido, repartidos entre os itens: pedido do Lira em 12/11/2026.
            'supplies_cost' => round($l['insumos'] ?? 0, 2),
            'net_profit' => round($l['margem'], 2),
        ];
    }

    // ------------------------------------------------------------------
    // Aba Lançamentos
    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function propsLancamentos(Request $request, array $f): array
    {
        $entriesSearch = TableSort::likeTerm($request->string('entries_search')->toString());
        $entriesSort = TableSort::resolve($request, [
            'entry_date' => 'entry_date',
            'description' => 'description',
            'type' => 'type',
            'amount' => 'amount',
            'costCenter' => 'cost_center_name',
        ], 'entry_date', 'desc', 'entries_');

        $doPeriodo = fn () => CashFlowEntry::query()
            ->where('entry_date', '>=', $f['de']->toDateString())
            ->where('entry_date', '<', $f['fim']->toDateString());

        return [
            'entries' => fn () => $doPeriodo()
                ->with('costCenter:id,name', 'creator:id,name')
                ->when($entriesSearch, function ($query) use ($entriesSearch) {
                    $query->where(function ($query) use ($entriesSearch) {
                        $query->where('description', 'like', $entriesSearch)
                            ->orWhereHas('costCenter', fn ($costCenter) => $costCenter->where('name', 'like', $entriesSearch));
                    });
                })
                ->when(
                    $entriesSort['column'] === 'cost_center_name',
                    fn ($query) => $query->orderBy(CostCenter::query()->select('name')->whereColumn('cost_centers.id', 'cash_flow_entries.cost_center_id'), $entriesSort['direction']),
                    fn ($query) => $query->orderBy($entriesSort['column'], $entriesSort['direction']),
                )
                ->orderByDesc('id')
                ->paginate(self::PER_PAGE, ['*'], 'entries_page')
                ->withQueryString(),
            // Entradas e saídas do período (antes: de todos os lançamentos,
            // sem período). A busca da tabela não mexe nos totais.
            'summary' => function () use ($doPeriodo) {
                $totais = $doPeriodo()
                    ->selectRaw('COALESCE(SUM(CASE WHEN type = ? THEN amount ELSE 0 END), 0) as income', [CashFlowEntry::TYPE_INCOME])
                    ->selectRaw('COALESCE(SUM(CASE WHEN type = ? THEN amount ELSE 0 END), 0) as expense', [CashFlowEntry::TYPE_EXPENSE])
                    ->toBase()
                    ->first();
                $income = round((float) $totais->income, 2);
                $expense = round((float) $totais->expense, 2);

                return ['balance' => round($income - $expense, 2), 'income' => $income, 'expense' => $expense];
            },
            'entriesFilter' => [
                'search' => $request->string('entries_search')->toString(),
                'sort' => $entriesSort['key'],
                'direction' => $entriesSort['direction'],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Gravações (rotas e regras de antes)
    // ------------------------------------------------------------------

    /**
     * Comissão digitada à mão na aba Vendas (pedido explícito 2026-08-14),
     * para quando o canal não devolveu a taxa real. Grava como
     * OrderChannelFee normal (source=manual). A taxa é por pedido: editar
     * numa linha vale para o pedido inteiro.
     */
    public function updateSaleFee(Request $request, Order $order): RedirectResponse
    {
        $validated = $request->validate([
            'fee_amount' => ['required', 'numeric', 'min:0'],
        ]);

        OrderChannelFee::query()->updateOrCreate(
            ['order_id' => $order->id, 'channel' => $order->origin],
            [
                'gross_amount' => $order->subtotal,
                'fee_amount' => $validated['fee_amount'],
                'source' => OrderChannelFee::SOURCE_MANUAL,
                'computed_at' => now(),
            ],
        );

        return back()->with('success', 'Comissão atualizada.');
    }

    /**
     * Custo do fornecedor editado à mão na aba Vendas. O valor digitado é o
     * custo TOTAL da linha (já × quantidade). Item com produto: vira o custo
     * UNITÁRIO do produto (vale para todas as vendas dele; a tela pede
     * confirmação). Item sem produto: grava só na linha (manual_cost_price).
     */
    public function updateItemCost(Request $request, OrderItem $orderItem): RedirectResponse
    {
        $validated = $request->validate([
            'product_cost' => ['required', 'numeric', 'min:0'],
        ]);

        if ($orderItem->product) {
            if ($orderItem->quantity < 1) {
                return back()->with('error', 'Quantidade inválida nesse item, não dá pra calcular o custo unitário.');
            }

            $orderItem->product->update([
                'cost_price' => round($validated['product_cost'] / $orderItem->quantity, 2),
            ]);
        } else {
            $orderItem->update(['manual_cost_price' => $validated['product_cost']]);
        }

        return back()->with('success', 'Custo do fornecedor atualizado.');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);
        $validated['created_by'] = $request->user()->id;

        CashFlowEntry::create($validated);

        return back()->with('success', 'Lançamento adicionado ao fluxo de caixa.');
    }

    public function update(Request $request, CashFlowEntry $cashFlowEntry): RedirectResponse
    {
        $cashFlowEntry->update($this->validated($request));

        return back()->with('success', 'Lançamento atualizado.');
    }

    public function destroy(CashFlowEntry $cashFlowEntry): RedirectResponse
    {
        $cashFlowEntry->delete();

        return back()->with('success', 'Lançamento removido.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in([CashFlowEntry::TYPE_INCOME, CashFlowEntry::TYPE_EXPENSE])],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'cost_center_id' => ['nullable', 'exists:cost_centers,id'],
            'entry_date' => ['required', 'date'],
        ]);
    }
}
