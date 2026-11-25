<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Admin\Support\Publicidade\PlataformaDeAnuncio;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Checkout\Support\FreteEmbutido;
use App\Modules\Financeiro\Support\GruposDeCanais;
use App\Modules\Marketplace\Models\ChannelAdSpend;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lucro líquido de CADA pedido dos sites (pedido do Lira em 25/11/2026: "uma
 * lista de pedidos do próprio site, que não seja grande, e a última coluna
 * seja o lucro líquido de cada pedido, já com tudo descontado").
 *
 * Nenhuma fórmula nova: cada parcela usa a mesma peça de ContributionMargin
 * que o card de lucro do painel usa, só que olhando um pedido.
 *
 *   receita     ContributionMargin::receitaSql() (site: subtotal − descontos)
 *   − produto   ContributionMargin::custoSql() de cada item
 *   − frete     pré-postagem dos Correios + etiqueta paga do Melhor Envio
 *               (etiquetaMelhorEnvioPaga) − TURBO que o cliente pagou
 *   − taxa      order_channel_fees do pedido; sem linha, ESTIMATIVA pelo meio
 *               de pagamento (Pix 0,99%, cartão 4,98%, boleto R$ 3,49 fixo)
 *   − imposto   aliquotaSimples() × nota de venda (sem nota, a receita),
 *               só a partir de LOJA_SIMPLES_DESDE
 *   − insumos   insumosPorPedido() + a parte do pedido nos lançamentos da
 *               tela Insumos do período (insumosLancados ÷ pedidos embalados)
 *   − anúncio   gasto do DIA na loja do pedido (channel_ad_spends; Meta da
 *               KazaKora entra como 'loja', igual porCanal()) ÷ pedidos
 *               pagos dessa loja nesse dia
 *   = lucro
 *
 * Diferenças para o card do painel (soma dos pedidos × porCanal()):
 *  - anúncio de dia sem nenhum pedido pago não cai em pedido nenhum;
 *  - taxa estimada aqui, que o painel só conta quando a linha existe;
 *  - cobrancasSemPedido (etiqueta de pedido cancelado) não é de um pedido.
 */
class LucroPorPedido
{
    /** Status de cada situação mostrada no card. */
    public const SITUACOES = [
        Order::STATUS_PAID => 'pago',
        Order::STATUS_SHIPPED => 'pago',
        Order::STATUS_COMPLETED => 'pago',
        Order::STATUS_PENDING => 'aguardando',
        Order::STATUS_AWAITING_PAYMENT => 'aguardando',
        Order::STATUS_CANCELLED => 'cancelado',
    ];

    /**
     * Os últimos pedidos dos canais (lista de canais de métrica, ver
     * Order::canalSql) criados em [desde, ate), do mais novo ao mais velho.
     *
     * @param  list<string>  $canais
     * @return list<array<string, mixed>>
     */
    public function ultimos(Carbon $desde, Carbon $ate, array $canais, int $limite = 10): array
    {
        $ids = DB::table('orders')
            ->tap(fn ($q) => Order::filtrarCanal($q, $canais))
            ->where('orders.created_at', '>=', $desde)
            ->where('orders.created_at', '<', $ate)
            ->where(fn ($q) => $q->whereNotIn('orders.fiscal_operation_type', ['purchase_return', 'sales_return'])->orWhereNull('orders.fiscal_operation_type'))
            ->orderByDesc('orders.created_at')
            ->orderByDesc('orders.id')
            ->limit($limite)
            ->pluck('orders.id')
            ->all();

        return $this->pedidos($ids, $desde, $ate);
    }

    /**
     * A conta de cada pedido da lista, na ordem dada. O período [desde, ate)
     * só serve ao rateio dos lançamentos da tela Insumos (como porCanal());
     * sem período, vale o mês de cada pedido.
     *
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    public function pedidos(array $ids, ?Carbon $desde = null, ?Carbon $ate = null): array
    {
        if ($ids === []) {
            return [];
        }

        $nota = 'SELECT i.valor_total FROM invoices i WHERE i.order_id = orders.id AND i.status = \'authorized\''
            ." AND i.operation_type = 'saida' AND i.cancelada_em IS NULL LIMIT 1";

        $pedidos = DB::table('orders')
            ->leftJoin('users', 'users.id', '=', 'orders.user_id')
            ->whereIn('orders.id', $ids)
            ->selectRaw('orders.id, orders.status, orders.created_at, orders.total, orders.shipping_cost, orders.shipping_carrier_name,
                orders.shipping_name, users.name as user_name, '.Order::canalSql().' as canal, '
                .ContributionMargin::receitaSql().' as receita, ('.$nota.') as nota')
            ->get()
            ->keyBy('id');

        // Venda efetiva: o mesmo filtro da receita do painel (estorno,
        // amostra e devolução concluída ficam de fora).
        $efetivas = DB::table('orders')
            ->whereIn('orders.id', $ids)
            ->whereIn('orders.status', ContributionMargin::REVENUE_STATUSES)
            ->tap(fn ($q) => ContributionMargin::semDevolucao($q))
            ->pluck('orders.id')
            ->flip();

        $itens = DB::table('order_items')
            ->leftJoin('products', 'products.id', '=', 'order_items.product_id')
            ->whereIn('order_items.order_id', $ids)
            ->orderBy('order_items.id')
            ->selectRaw('order_items.order_id, order_items.product_name, order_items.quantity, '.ContributionMargin::custoSql().' as custo')
            ->get()
            ->groupBy('order_id');

        $taxas = DB::table('order_channel_fees')
            ->whereIn('order_id', $ids)
            ->selectRaw('order_id, SUM(fee_amount) as total')
            ->groupBy('order_id')
            ->pluck('total', 'order_id');

        // Meio de pagamento do último pagamento (para a taxa estimada).
        $meios = DB::table('payments')
            ->whereIn('order_id', $ids)
            ->orderBy('id')
            ->get(['order_id', 'method_type', 'status'])
            ->groupBy('order_id')
            ->map(fn (Collection $pgs) => ($pgs->first(fn ($p) => in_array($p->status, [Payment::STATUS_CAPTURED, Payment::STATUS_AUTHORIZED], true)) ?? $pgs->last())->method_type);

        $correios = DB::table('correios_pre_postagens')
            ->whereIn('order_id', $ids)
            ->where('status', CorreiosPrePostagem::STATUS_GERADA)
            ->where('devolucao', false)
            ->selectRaw('order_id, SUM(postage_price) as total')
            ->groupBy('order_id')
            ->pluck('total', 'order_id');
        $melhorEnvio = DB::table('melhor_envio_envios')
            ->whereIn('order_id', $ids)
            ->tap(fn ($q) => ContributionMargin::etiquetaMelhorEnvioPaga($q))
            ->selectRaw('order_id, SUM(preco) as total')
            ->groupBy('order_id')
            ->pluck('total', 'order_id');

        $anuncioPorPedido = $this->anuncioPorPedido($pedidos->filter(fn ($p) => $efetivas->has($p->id)));
        $insumosLancados = $this->insumosLancadosPorPedido($pedidos->filter(fn ($p) => $efetivas->has($p->id)), $desde, $ate);

        $simplesDesde = Carbon::parse((string) config('loja.simples.desde', '2026-09-01'))->startOfDay();
        $aliquota = ContributionMargin::aliquotaSimples();

        $linhas = [];
        foreach ($ids as $id) {
            $p = $pedidos->get($id);
            if (! $p) {
                continue;
            }
            $criadoEm = Carbon::parse($p->created_at);
            $situacao = self::SITUACOES[$p->status] ?? 'aguardando';
            $linha = [
                'id' => (int) $p->id,
                'criado_em' => $criadoEm->toIso8601String(),
                'cliente' => self::primeiroNome($p->shipping_name ?: $p->user_name),
                'canal' => (string) $p->canal,
                'loja' => GruposDeCanais::nomeDoCanal((string) $p->canal),
                'itens' => self::resumoItens($itens->get($id, collect())),
                'pago' => round((float) $p->total, 2),
                'situacao' => $situacao,
                'lucro' => null,
                'conta' => null,
                'sem_lucro' => null,
            ];

            if (! $efetivas->has($id)) {
                $linha['sem_lucro'] = match (true) {
                    $situacao === 'aguardando' => 'aguardando pagamento',
                    $situacao === 'cancelado' => 'cancelado',
                    default => 'fora da venda (estorno ou devolução)',
                };
                $linhas[] = $linha;

                continue;
            }

            $receita = (float) $p->receita;
            $produto = (float) $itens->get($id, collect())->sum(fn ($i) => (float) $i->custo);

            $temEtiqueta = $correios->has($id) || $melhorEnvio->has($id);
            $frete = (float) ($correios[$id] ?? 0) + (float) ($melhorEnvio[$id] ?? 0)
                - ($temEtiqueta && $p->shipping_carrier_name === FreteEmbutido::TURBO ? (float) $p->shipping_cost : 0);

            $taxaEstimada = ! $taxas->has($id);
            $taxa = $taxaEstimada ? self::taxaEstimada($meios[$id] ?? null, (float) $p->total) : (float) $taxas[$id];

            $imposto = $criadoEm->gte($simplesDesde) && $aliquota > 0
                ? round(($p->nota !== null ? (float) $p->nota : $receita) * $aliquota / 100, 2)
                : 0.0;
            $insumos = ContributionMargin::insumosPorPedido() + ($insumosLancados[$id] ?? 0.0);
            $anuncio = $anuncioPorPedido[$id] ?? 0.0;

            $conta = [
                'receita' => round($receita, 2),
                'produto' => round($produto, 2),
                'frete' => round($frete, 2),
                'taxa' => round($taxa, 2),
                'taxa_estimada' => $taxaEstimada,
                'imposto' => $imposto,
                'insumos' => round($insumos, 2),
                'anuncio' => round($anuncio, 2),
                'anuncio_pedidos_no_dia' => $anuncioPorPedido['__pedidos'][$id] ?? 0,
            ];
            $linha['conta'] = $conta;
            $linha['lucro'] = round($conta['receita'] - $conta['produto'] - $conta['frete'] - $conta['taxa']
                - $conta['imposto'] - $conta['insumos'] - $conta['anuncio'], 2);
            $linhas[] = $linha;
        }

        return $linhas;
    }

    /**
     * Anúncio do dia da loja ÷ pedidos pagos (venda efetiva) da loja no dia.
     * Mesmo gasto do painel: channel_ad_spends, com a Meta da KazaKora
     * (meta_ads) somada ao canal 'loja', como em porCanal().
     *
     * @return array<int|string, mixed> por order_id, e '__pedidos' => [order_id => pedidos no dia]
     */
    private function anuncioPorPedido(Collection $pedidos): array
    {
        if ($pedidos->isEmpty()) {
            return [];
        }

        $dias = $pedidos->map(fn ($p) => Carbon::parse($p->created_at)->toDateString())->unique();
        $inicio = Carbon::parse($dias->min());
        $fim = Carbon::parse($dias->max())->addDay();
        $canais = $pedidos->pluck('canal')->unique()->values()->all();
        $canaisAds = in_array(Order::ORIGIN_STORE, $canais, true) ? [...$canais, PlataformaDeAnuncio::META] : $canais;

        $gasto = [];
        ChannelAdSpend::query()
            ->where('date', '>=', $inicio->toDateString())
            ->where('date', '<', $fim->toDateString())
            ->whereIn('channel', $canaisAds)
            ->get(['date', 'channel', 'spend'])
            ->each(function ($g) use (&$gasto) {
                $canal = $g->channel === PlataformaDeAnuncio::META ? Order::ORIGIN_STORE : $g->channel;
                $chave = $canal.'|'.Carbon::parse($g->date)->toDateString();
                $gasto[$chave] = ($gasto[$chave] ?? 0.0) + (float) $g->spend;
            });

        $contagem = [];
        DB::table('orders')
            ->whereIn('orders.status', ContributionMargin::REVENUE_STATUSES)
            ->where('orders.created_at', '>=', $inicio)
            ->where('orders.created_at', '<', $fim)
            ->tap(fn ($q) => Order::filtrarCanal($q, $canais))
            ->tap(fn ($q) => ContributionMargin::semDevolucao($q))
            ->get(['orders.created_at', DB::raw(Order::canalSql().' as canal')])
            ->each(function ($o) use (&$contagem) {
                $chave = $o->canal.'|'.Carbon::parse($o->created_at)->toDateString();
                $contagem[$chave] = ($contagem[$chave] ?? 0) + 1;
            });

        $porPedido = ['__pedidos' => []];
        foreach ($pedidos as $p) {
            $chave = $p->canal.'|'.Carbon::parse($p->created_at)->toDateString();
            $n = max(1, $contagem[$chave] ?? 1);
            $porPedido[$p->id] = ($gasto[$chave] ?? 0.0) / $n;
            $porPedido['__pedidos'][$p->id] = $n;
        }

        return $porPedido;
    }

    /**
     * Parte de cada pedido nos lançamentos da tela Insumos: o total do
     * período ÷ pedidos embalados de TODOS os canais no período (o mesmo
     * rateio de insumosLancadosPorCanal()). Sem período, o mês do pedido.
     *
     * @return array<int, float>
     */
    private function insumosLancadosPorPedido(Collection $pedidos, ?Carbon $desde, ?Carbon $ate): array
    {
        $parte = [];
        $cache = [];
        foreach ($pedidos as $p) {
            $criado = Carbon::parse($p->created_at);
            [$de, $fim] = $desde && $ate ? [$desde, $ate] : [$criado->copy()->startOfMonth(), $criado->copy()->startOfMonth()->addMonthNoOverflow()];
            $chave = $de->toDateTimeString().'|'.$fim->toDateTimeString();
            $cache[$chave] ??= (function () use ($de, $fim) {
                $total = ContributionMargin::insumosLancados($de, $fim);
                $embalados = $total == 0.0 ? 0 : array_sum(ContributionMargin::pedidosEmbalados($de, $fim));

                return $embalados > 0 ? $total / $embalados : 0.0;
            })();
            $parte[$p->id] = $cache[$chave];
        }

        return $parte;
    }

    /**
     * Taxa do pagamento quando o pedido ainda não tem order_channel_fees
     * (a taxa real do Mercado Pago chega pela conciliação da madrugada).
     * Pix e cartão: as mesmas taxas das Ofertas do dia (config/ofertas.php);
     * boleto: tarifa fixa. Meio desconhecido: cartão (pior caso).
     */
    public static function taxaEstimada(?string $meio, float $pago): float
    {
        return round(match ($meio) {
            Payment::METHOD_PIX => $pago * (float) config('ofertas.taxa_pix', 0.99) / 100,
            Payment::METHOD_BOLETO => (float) config('ofertas.taxa_boleto', 3.49),
            default => $pago * (float) config('ofertas.taxa_cartao', 4.98) / 100,
        }, 2);
    }

    private static function primeiroNome(?string $nome): string
    {
        $nome = trim((string) $nome);

        return $nome === '' ? '—' : Str::ucfirst(Str::lower(Str::before($nome.' ', ' ')));
    }

    /** "2× Travesseiro…" e, se tiver mais itens, "+1". */
    private static function resumoItens(Collection $itens): string
    {
        $primeiro = $itens->first();
        if (! $primeiro) {
            return '—';
        }

        $texto = ((int) $primeiro->quantity).'× '.Str::limit((string) $primeiro->product_name, 26, '…');

        return $itens->count() > 1 ? $texto.' +'.($itens->count() - 1) : $texto;
    }
}
