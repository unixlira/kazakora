<?php

namespace App\Modules\Notifications\Push;

use App\Models\User;
use App\Modules\Admin\Support\Publicidade\CampanhasMetaPorLoja;
use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Marketplace\Models\ChannelAdSpend;
use App\Modules\Marketplace\Support\ContributionMargin;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tela principal dos apps de vendas (pedido do Lira em 2026-11-19): números
 * SÓ da loja própria daquele app (kazakora.com no app KazaKora, ferrakora.com
 * no app FerraKora). Marketplace nunca entra.
 *
 * De onde vem cada número (nada de conta nova):
 *  - Pedido da loja: Order::filtrarCanal ('loja' = kazakora.com, site_origem
 *    nulo; 'ferrakora' = site_origem ferrakora), o mesmo filtro da tela de
 *    pedidos.
 *  - Pago = status pago/enviado/entregue (ContributionMargin::REVENUE_STATUSES)
 *    e venda efetiva (sem devolução, estorno, amostra). Faturamento = total
 *    que o cliente pagou (orders.total), o valor que a tela de pedidos mostra.
 *  - Lucro: ContributionMargin::porCanal(), linha do canal da loja (a mesma
 *    conta do Financeiro, separada por canal; periodo() não filtra canal).
 *  - Visitas: site_visits do site, gente de fora (SiteVisit::visitantesPorDia
 *    + soDeFora), igual ao painel.
 *  - Meta: gasto diário da conta de anúncios da loja (MetaAdsService pela
 *    CampanhasMetaPorLoja::servicoDa), lido da Meta.
 *
 * Comparação: pedidos, faturamento e visitas contra a mesma janela do
 * período anterior, até a mesma hora (hoje × ontem até agora). Lucro e Meta
 * são por dia inteiro: comparam com os N dias anteriores completos e, em
 * "hoje", não comparam (dia pela metade contra dia inteiro).
 */
class MetricasDaLoja
{
    public const PERIODOS = ['hoje' => 1, '7d' => 7, '30d' => 30];

    public const CANAL = [
        AppsDeVendas::KAZAKORA => Order::ORIGIN_STORE,
        AppsDeVendas::FERRAKORA => Order::SITE_FERRAKORA,
    ];

    public const SITE_VISITAS = [
        AppsDeVendas::KAZAKORA => SiteVisit::SITE_LOJA,
        AppsDeVendas::FERRAKORA => 'ferrakora',
    ];

    public const STATUS = [
        Order::STATUS_PENDING => 'Aguardando pagamento',
        Order::STATUS_AWAITING_PAYMENT => 'Aguardando pagamento',
        Order::STATUS_PAID => 'Pago',
        Order::STATUS_SHIPPED => 'Enviado',
        Order::STATUS_COMPLETED => 'Entregue',
        Order::STATUS_CANCELLED => 'Cancelado',
    ];

    private const CACHE = 'vendas-app:metricas:v1:';

    public function __construct(
        private readonly ContributionMargin $margem,
        private readonly CampanhasMetaPorLoja $metaPorLoja,
    ) {}

    public static function periodo(?string $pedido): string
    {
        return array_key_exists((string) $pedido, self::PERIODOS) ? (string) $pedido : 'hoje';
    }

    /** Guardado 60 s: o app atualiza a cada 60 s com a tela aberta. */
    public function montar(string $app, string $periodo): array
    {
        return Cache::remember(self::CACHE.$app.':'.$periodo, 60, fn () => $this->calcular($app, $periodo));
    }

    public function calcular(string $app, string $periodo, ?Carbon $agora = null): array
    {
        $agora ??= now();
        $dias = self::PERIODOS[$periodo];
        $inicio = $agora->copy()->startOfDay()->subDays($dias - 1);
        $inicioAnt = $inicio->copy()->subDays($dias);
        $fimAnt = $agora->copy()->subDays($dias);
        $canal = self::CANAL[$app];

        $atual = $this->vendas($canal, $inicio, $agora);
        $anterior = $this->vendas($canal, $inicioAnt, $fimAnt);
        $visitas = $this->visitas($app, $inicio, $agora);
        $visitasAnt = $this->visitas($app, $inicioAnt, $fimAnt);
        $conversao = $visitas > 0 ? round($atual['pedidos'] / $visitas * 100, 2) : null;
        $conversaoAnt = $visitasAnt > 0 ? round($anterior['pedidos'] / $visitasAnt * 100, 2) : null;

        $comparaDiaInteiro = $periodo !== 'hoje';
        $meta = $this->meta($app, $inicioAnt, $inicio, $agora);
        $roas = ($meta['gasto'] ?? 0) > 0 ? round($atual['faturamento'] / $meta['gasto'], 2) : null;
        $roasAnt = ($meta['gasto_anterior'] ?? 0) > 0 ? round($this->vendas($canal, $inicioAnt, $inicio)['faturamento'] / $meta['gasto_anterior'], 2) : null;

        return [
            'app' => $app,
            'loja' => $app === AppsDeVendas::FERRAKORA ? 'ferrakora.com' : 'kazakora.com',
            'periodo' => $periodo,
            'de' => $inicio->toIso8601String(),
            'ate' => $agora->toIso8601String(),
            'gerado_em' => $agora->toIso8601String(),
            'faturamento' => self::numero($atual['faturamento'], $anterior['faturamento']),
            'pedidos' => self::numero($atual['pedidos'], $anterior['pedidos']),
            'ticket_medio' => self::numero($atual['ticket'], $anterior['ticket']),
            'lucro' => $this->lucro($app, $canal, $inicio, $inicioAnt, $agora, $comparaDiaInteiro),
            'aguardando_pagamento' => $this->aguardando($canal, $inicio, $agora),
            'visitas' => self::numero($visitas, $visitasAnt),
            'conversao' => self::numero($conversao, $conversaoAnt),
            'meta' => isset($meta['erro'])
                ? ['erro' => $meta['erro'], 'gasto' => null, 'roas' => null]
                : [
                    'erro' => null,
                    'configurada' => $meta['configurada'],
                    'gasto' => self::numero($meta['gasto'], $comparaDiaInteiro ? $meta['gasto_anterior'] : null),
                    'roas' => self::numero($roas, $comparaDiaInteiro ? $roasAnt : null),
                    'vendas_atribuidas' => $meta['vendas'],
                ],
            'top_produtos' => $this->topProdutos($canal, $inicio, $agora),
            'ultimos_pedidos' => $this->ultimosPedidos($canal),
            'serie' => $this->serie($app, $canal, $periodo, $inicio, $agora),
        ];
    }

    /** @return array{valor: int|float|null, anterior: int|float|null, variacao: float|null} */
    private static function numero(int|float|null $valor, int|float|null $anterior): array
    {
        $variacao = $valor !== null && $anterior !== null && (float) $anterior != 0.0
            ? round(($valor - $anterior) / abs($anterior) * 100, 1)
            : null;

        return ['valor' => $valor, 'anterior' => $anterior, 'variacao' => $variacao];
    }

    private function pagos(string $canal, Carbon $de, Carbon $ate)
    {
        return Order::filtrarCanal(Order::query()->vendaEfetiva(), $canal)
            ->whereIn('orders.status', ContributionMargin::REVENUE_STATUSES)
            ->where('orders.created_at', '>=', $de)
            ->where('orders.created_at', '<=', $ate);
    }

    /** @return array{faturamento: float, pedidos: int, ticket: float|null} */
    private function vendas(string $canal, Carbon $de, Carbon $ate): array
    {
        $linha = $this->pagos($canal, $de, $ate)->toBase()
            ->selectRaw('COUNT(*) as pedidos, COALESCE(SUM(orders.total), 0) as faturamento')->first();
        $pedidos = (int) ($linha->pedidos ?? 0);
        $faturamento = round((float) ($linha->faturamento ?? 0), 2);

        return ['faturamento' => $faturamento, 'pedidos' => $pedidos, 'ticket' => $pedidos > 0 ? round($faturamento / $pedidos, 2) : null];
    }

    /**
     * Lucro da loja pela conta oficial (porCanal, linha do canal). Na
     * FerraKora, só se o gasto da conta Meta dela entra na conta
     * (ads:sync-spend grava com canal 'ferrakora'); sem isso o lucro sairia
     * maior que o real e fica de fora, com o motivo.
     */
    private function lucro(string $app, string $canal, Carbon $inicio, Carbon $inicioAnt, Carbon $agora, bool $comparar): array
    {
        if ($app === AppsDeVendas::FERRAKORA
            && filled(config('services.meta_ads.ferrakora_ad_account_id'))
            && ! ChannelAdSpend::query()->where('channel', Order::SITE_FERRAKORA)->exists()) {
            return ['valor' => null, 'anterior' => null, 'variacao' => null, 'margem' => null,
                'motivo' => 'O gasto da conta Meta da FerraKora ainda não entra na conta de lucro; o número sairia maior que o real.'];
        }

        $amanha = $agora->copy()->startOfDay()->addDay();
        $linha = $this->margem->porCanal($inicio, $amanha)[$canal] ?? null;
        $anterior = $comparar ? ($this->margem->porCanal($inicioAnt, $inicio)[$canal] ?? null) : null;
        $lucro = $linha ? (float) $linha['lucro'] : 0.0;
        $receita = $linha ? (float) $linha['receita'] : 0.0;

        return self::numero($lucro, $comparar ? (float) ($anterior['lucro'] ?? 0) : null) + [
            'margem' => $receita > 0 ? round($lucro / $receita * 100, 1) : null,
            'motivo' => null,
        ];
    }

    /** Pix e boleto gerados no período e ainda não pagos. */
    private function aguardando(string $canal, Carbon $de, Carbon $ate): array
    {
        $linha = Order::filtrarCanal(Order::query(), $canal)
            ->whereIn('orders.status', [Order::STATUS_PENDING, Order::STATUS_AWAITING_PAYMENT])
            ->where('orders.created_at', '>=', $de)
            ->where('orders.created_at', '<=', $ate)
            ->whereHas('payments', fn ($q) => $q->whereIn('method_type', [Payment::METHOD_PIX, Payment::METHOD_BOLETO]))
            ->toBase()
            ->selectRaw('COUNT(*) as pedidos, COALESCE(SUM(orders.total), 0) as valor')->first();

        return ['pedidos' => (int) ($linha->pedidos ?? 0), 'valor' => round((float) ($linha->valor ?? 0), 2)];
    }

    /** Visitantes de fora no período (regra do painel: sem equipe e sem robô). */
    private function visitas(string $app, Carbon $de, Carbon $ate): int
    {
        return $this->visitantes($app, $de, $ate)->pluck('visitante')->unique()->count();
    }

    /** Linhas de SiteVisit::soDeFora (um visitante por dia, com as origens). */
    private function visitantes(string $app, Carbon $de, Carbon $ate)
    {
        $equipe = User::query()->whereIn('role', User::STAFF_ROLES)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return SiteVisit::soDeFora(SiteVisit::visitantesPorDia($this->doSite($app, $de, $ate), $equipe));
    }

    private function doSite(string $app, Carbon $de, Carbon $ate)
    {
        return SiteVisit::query()->where('site_visits.site', self::SITE_VISITAS[$app])
            ->where('created_at', '>=', $de)->where('created_at', '<=', $ate);
    }

    /**
     * Gráfico da tela: visitantes e pedidos pagos por dia (7 e 30 dias) ou
     * por hora (hoje). Os visitantes são os mesmos do total: no "hoje", cada
     * um conta na hora da primeira página.
     *
     * @return list<array{rotulo: string, visitas: int, pedidos: int}>
     */
    private function serie(string $app, string $canal, string $periodo, Carbon $inicio, Carbon $agora): array
    {
        $porHora = $periodo === 'hoje';
        $baldes = [];
        for ($t = $inicio->copy(); $t->lte($agora); $porHora ? $t->addHour() : $t->addDay()) {
            $baldes[$porHora ? $t->format('H') : $t->toDateString()] = ['rotulo' => $porHora ? $t->format('H').'h' : $t->format('d/m'), 'visitas' => 0, 'pedidos' => 0];
        }
        $chave = fn ($quando) => $porHora ? Carbon::parse($quando)->format('H') : Carbon::parse($quando)->toDateString();

        $this->pagos($canal, $inicio, $agora)->pluck('orders.created_at')
            ->each(function ($quando) use (&$baldes, $chave) {
                if (isset($baldes[$k = $chave($quando)])) {
                    $baldes[$k]['pedidos']++;
                }
            });

        $linhas = $this->visitantes($app, $inicio, $agora);
        if ($porHora) {
            $primeira = $this->doSite($app, $inicio, $agora)->toBase()
                ->selectRaw('visitor_id, ip, MIN(created_at) as primeira')->groupBy('visitor_id', 'ip')->get()
                ->mapWithKeys(fn ($l) => [$l->visitor_id.'|'.$l->ip => $l->primeira]);
            foreach ($linhas as $linha) {
                $quando = collect($linha->origens)->map(fn ($o) => $primeira->get($o[0].'|'.$o[1]))->filter()->min();
                if ($quando && isset($baldes[$k = $chave($quando)])) {
                    $baldes[$k]['visitas']++;
                }
            }
        } else {
            foreach ($linhas as $linha) {
                if (isset($baldes[$linha->dia])) {
                    $baldes[$linha->dia]['visitas']++;
                }
            }
        }

        return array_values($baldes);
    }

    /**
     * Gasto da conta Meta da loja: dias [inicio, hoje] e os N dias antes.
     * Uma leitura só, guardada 5 minutos; erro da Meta não derruba a tela.
     */
    private function meta(string $app, Carbon $inicioAnt, Carbon $inicio, Carbon $agora): array
    {
        $loja = $app === AppsDeVendas::FERRAKORA ? CampanhasMetaPorLoja::FERRAKORA : CampanhasMetaPorLoja::KAZAKORA;
        if (! isset($this->metaPorLoja->lojas()[$loja])) {
            return ['configurada' => false, 'gasto' => null, 'gasto_anterior' => null, 'vendas' => null];
        }

        try {
            $linhas = Cache::remember(
                self::CACHE.'meta:'.$loja.':'.$inicioAnt->toDateString().':'.$agora->toDateString(),
                now()->addMinutes(5),
                fn () => $this->metaPorLoja->servicoDa($loja)->gastoDiario($inicioAnt, $agora),
            );
        } catch (Throwable $erro) {
            return ['erro' => 'Meta fora do ar agora: '.mb_strimwidth($erro->getMessage(), 0, 120, '…')];
        }

        $gasto = $anterior = $vendas = 0.0;
        foreach ($linhas as $linha) {
            if ((string) $linha['date'] >= $inicio->toDateString()) {
                $gasto += (float) $linha['spend'];
                $vendas += (float) $linha['attributed_gmv'];
            } else {
                $anterior += (float) $linha['spend'];
            }
        }

        return ['configurada' => true, 'gasto' => round($gasto, 2), 'gasto_anterior' => round($anterior, 2), 'vendas' => round($vendas, 2)];
    }

    /** @return list<array{nome: string, quantidade: int, valor: float}> */
    private function topProdutos(string $canal, Carbon $de, Carbon $ate): array
    {
        $ids = $this->pagos($canal, $de, $ate)->select('orders.id');

        return OrderItem::query()->toBase()
            ->whereIn('order_id', $ids)
            ->selectRaw('product_id, product_name, SUM(quantity) as quantidade, SUM(subtotal) as valor')
            ->groupBy('product_id', 'product_name')
            ->get()
            ->groupBy(fn ($l) => $l->product_id !== null ? 'p'.$l->product_id : 'n'.$l->product_name)
            ->map(fn ($g) => [
                'nome' => (string) $g->first()->product_name,
                'quantidade' => (int) $g->sum('quantidade'),
                'valor' => round((float) $g->sum('valor'), 2),
            ])
            ->sortByDesc(fn ($p) => [$p['valor'], $p['quantidade']])
            ->take(5)
            ->values()
            ->all();
    }

    /** Sem dado do cliente: número, valor, status, hora e o produto principal. */
    private function ultimosPedidos(string $canal): array
    {
        return Order::filtrarCanal(Order::query(), $canal)
            ->nonPurchaseReturn()
            ->with(['items' => fn ($q) => $q->select('id', 'order_id', 'product_name', 'quantity')->orderBy('id')])
            ->latest('orders.created_at')->latest('orders.id')
            ->limit(10)
            ->get(['orders.id', 'orders.total', 'orders.status', 'orders.created_at'])
            ->map(fn (Order $o) => [
                'id' => $o->id,
                'total' => round((float) $o->total, 2),
                'status' => $o->status,
                'status_rotulo' => self::STATUS[$o->status] ?? ucfirst((string) $o->status),
                'pago' => in_array($o->status, ContributionMargin::REVENUE_STATUSES, true),
                'criado_em' => $o->created_at?->toIso8601String(),
                'produto' => $o->items->first()?->product_name,
                'itens' => (int) $o->items->sum('quantity'),
            ])
            ->all();
    }
}
