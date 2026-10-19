<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cadastros\Models\CostCenter;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Financeiro\Models\CashFlowEntry;
use App\Modules\Financeiro\Support\AlertasFinanceiros;
use App\Modules\Financeiro\Support\PrevisaoDeCaixa;
use App\Modules\Financeiro\Support\SaldosCanais;
use App\Modules\Marketplace\Models\OrderChannelFee;
use App\Modules\Marketplace\Support\ContributionMargin;
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
 * Documents/2026-10-19-proposta-fluxo-de-caixa.md). Quatro abas:
 *
 * - Resumo: alertas, KPIs, cascata, lucro por canal, evolução, próximos 30
 *   dias e confiabilidade. Tudo pela conta oficial (ContributionMargin).
 * - Vendas (custo e comissão): a tabela item a item, agora pela mesma conta
 *   (ContributionMargin::porItem); antes fazia conta própria e dava margem
 *   4 vezes maior que a oficial.
 * - Saldos nos canais (2026-10-19): saldo disponível e repasses a receber
 *   de cada marketplace, por empresa, com a evolução de hora em hora.
 * - Lançamentos: entradas e saídas digitadas à mão, filtradas pelo período.
 *
 * Só a aba aberta é calculada: cada aba manda as próprias props.
 */
class CashFlowController extends Controller
{
    private const PER_PAGE = 50;

    private const ABAS = ['resumo', 'saldos', 'vendas', 'lancamentos'];

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

    public function index(Request $request): Response
    {
        $filtro = $this->filtro($request);
        $aba = $filtro['aba'];

        $props = [
            'filtro' => $filtro['publico'],
            'canaisFiltro' => fn () => $this->canaisDoFiltro($filtro),
            'costCenters' => fn () => CostCenter::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ];

        if ($aba === 'resumo') {
            $props += $this->propsResumo($filtro);
        } elseif ($aba === 'saldos') {
            // Fotos de hora em hora (financeiro:coletar-saldos): a aba não
            // chama API de marketplace, só lê saldos_canais.
            $props['saldos'] = fn () => app(SaldosCanais::class)->painel();
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
    private function filtro(Request $request): array
    {
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
        $canal = array_key_exists($canal, self::CANAIS) ? $canal : null;

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
            ->filter(fn ($c) => isset(self::CANAIS[$c]))
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
        $memo = [];
        $uma = function (string $chave, \Closure $calc) use (&$memo) {
            return $memo[$chave] ??= $calc();
        };

        $porCanal = fn () => $uma('porCanal', fn () => $this->margem->porCanal($f['de'], $f['fim']));
        $qualidade = fn () => $uma('qualidade', fn () => ContributionMargin::qualidadeTaxas($f['de'], $f['fim']));
        $resumo = fn () => $uma('resumo', function () use ($f, $canal, $porCanal) {
            $atual = $this->numeros($f['de'], $f['fim'], $canal, $porCanal());
            $comparacao = $f['compDe'] ? $this->numeros($f['compDe'], $f['compFim'], $canal) : null;

            return ['atual' => $atual, 'comparacao' => $comparacao, 'aliquota' => ContributionMargin::aliquotaSimples(),
                'adsMeta' => (float) config('loja.ads_meta_percentual', 4), 'adsLimite' => (float) config('loja.ads_alerta_percentual', 6)];
        });
        $pendencias = fn () => $uma('pendencias', fn () => $this->pendencias($f['de'], $f['fim'], $canal));
        $das = fn () => $uma('das', fn () => app(PrevisaoDeCaixa::class)->das());

        return [
            'resumo' => $resumo,
            'canais' => function () use ($porCanal, $qualidade, $canal) {
                $linhas = $porCanal();
                if ($canal === null) {
                    foreach (self::CANAIS_FIXOS as $fixo) {
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
            'serie' => function () use ($f, $canal) {
                $atual = $this->margem->serie($f['de'], $f['fim'], $f['agrupar'], $canal);
                $anterior = $f['compDe'] ? $this->margem->serie($f['compDe'], $f['compFim'], $f['agrupar'], $canal) : [];

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
            'previsao' => fn () => app(PrevisaoDeCaixa::class)->proximosDias(),
            'qualidade' => function () use ($qualidade, $pendencias, $canal) {
                $q = $qualidade();
                if ($canal !== null) {
                    $q = array_intersect_key($q, [$canal => true]);
                }

                return [
                    'canais' => collect($q)->map(fn ($l, $c) => ['canal' => $c, 'nome' => self::CANAIS[$c] ?? $c] + $l)->values()->all(),
                    'pendencias' => $pendencias(),
                ];
            },
            'alertas' => function () use ($f, $canal, $porCanal, $qualidade, $pendencias, $resumo, $das) {
                $r = $resumo();
                $linhas = $porCanal();
                if ($canal !== null) {
                    $linhas = array_intersect_key($linhas, [$canal => true]);
                }
                $q = $qualidade();
                if ($canal !== null) {
                    $q = array_intersect_key($q, [$canal => true]);
                }
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
                    'linkSemCusto' => '/admin/fluxo-de-caixa?'.http_build_query(array_filter([
                        'aba' => 'vendas', 'sem_custo' => 1, 'de' => $f['de']->toDateString(), 'ate' => $f['ate']->toDateString(), 'canal' => $canal,
                    ])),
                    'notasCanceladas' => $p['notasCanceladas'],
                ]);
            },
        ];
    }

    /**
     * Números dos cartões: a conta oficial + vendas brutas, faturamento em
     * nota e pedidos.
     *
     * @return array<string, float|int>
     */
    private function numeros(Carbon $desde, Carbon $fim, ?string $canal, ?array $porCanal = null): array
    {
        $porCanal ??= $this->margem->porCanal($desde, $fim);
        $conta = $this->margem->conta($desde, $fim, $canal);
        $base = ContributionMargin::baseImposto($desde, $fim);
        $pedidos = $canal === null
            ? (int) array_sum(array_column($porCanal, 'pedidos'))
            : (int) ($porCanal[$canal]['pedidos'] ?? 0);

        return [
            'receita' => (float) $conta['receita'],
            'custo_produtos' => (float) $conta['custo_produtos'],
            'taxas' => (float) $conta['taxas'],
            'frete' => (float) $conta['frete'],
            'ads' => (float) $conta['ads'],
            'bonificacoes' => (float) $conta['bonificacoes'],
            'margem' => (float) $conta['margem'],
            'imposto' => (float) $conta['imposto'],
            'lucro' => (float) $conta['lucro'],
            'pedidos' => $pedidos,
            'vendas_brutas' => ContributionMargin::vendasBrutas($desde, $fim, $canal),
            'faturamento_nota' => round($canal === null ? array_sum($base) : (float) ($base[$canal] ?? 0), 2),
            'pedidos_site' => $canal === null || $canal === Order::ORIGIN_STORE ? (int) ($porCanal[Order::ORIGIN_STORE]['pedidos'] ?? 0) : null,
        ];
    }

    /**
     * Itens sem custo, notas autorizadas de pedido cancelado e vendas sem
     * nota no período (bloco 7).
     *
     * @return array{semCusto: int, notasCanceladas: array{quantidade: int, valor: float}, semNota: int}
     */
    private function pendencias(Carbon $desde, Carbon $fim, ?string $canal): array
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
            'net_profit' => 'margem',
        ], 'pedido_em');

        $memo = null;
        $dados = function () use (&$memo, $f, $search, $semCusto, $taxaEstimada, $sort) {
            if ($memo !== null) {
                return $memo;
            }
            $conta = $this->margem->porItem($f['de'], $f['fim'], $f['canal']);
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
                    'netProfit' => round($margemLinhas, 2),
                    'ads' => $conta['canal']['ads'],
                    'flex' => round((float) $conta['canal']['flex'], 2),
                    'bonificacoes' => $conta['canal']['bonificacoes'],
                    // Igual à margem do Resumo (periodo/porCanal) quando não
                    // há busca nem filtro de linha.
                    'margemOficial' => $filtrado ? null : round($margemLinhas - $conta['canal']['ads'] - $conta['canal']['flex'] + $conta['canal']['bonificacoes'], 2),
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
