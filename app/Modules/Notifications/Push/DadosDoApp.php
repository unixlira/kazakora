<?php

namespace App\Modules\Notifications\Push;

use App\Modules\Admin\Support\Publicidade\CampanhasMetaPorLoja;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Checkout\Models\Payment;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use App\Modules\Marketplace\Support\ContributionMargin;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Telas do app de vendas (2026-11-19, "não pode parecer o painel"): o app
 * desenha Pedidos, Produtos e Campanhas a partir destes JSON, sempre só da
 * loja do app. Pedido de outra loja ou de marketplace não existe aqui (404).
 * Do cliente vai só o primeiro nome e a cidade: nada de endereço, telefone,
 * e-mail ou documento.
 */
class DadosDoApp
{
    private const POR_PAGINA = 30;

    public const FILTROS_STATUS = [
        'pagos' => ContributionMargin::REVENUE_STATUSES,
        'aguardando' => [Order::STATUS_PENDING, Order::STATUS_AWAITING_PAYMENT],
        'cancelados' => [Order::STATUS_CANCELLED],
    ];

    public const PERIODOS_META = ['hoje' => 'today', '7d' => 'last_7d', 'mes' => 'this_month', '30d' => 'last_30d'];

    public function __construct(
        private readonly CampanhasMetaPorLoja $metaPorLoja,
        private readonly ContributionMargin $margem,
    ) {}

    private function daLoja(string $app)
    {
        return Order::filtrarCanal(Order::query(), MetricasDaLoja::CANAL[$app])->nonPurchaseReturn();
    }

    public function pedidos(string $app, ?string $status, int $pagina): array
    {
        $pagina = max(1, $pagina);
        $query = $this->daLoja($app)
            ->when(isset(self::FILTROS_STATUS[$status]), fn ($q) => $q->whereIn('orders.status', self::FILTROS_STATUS[$status]))
            ->latest('orders.created_at')->latest('orders.id');

        $lista = (clone $query)->with(['items' => fn ($q) => $q->select('id', 'order_id', 'product_id', 'product_name', 'quantity')->orderBy('id'), 'items.product.images'])
            ->forPage($pagina, self::POR_PAGINA + 1)
            ->get(['orders.id', 'orders.total', 'orders.status', 'orders.created_at']);

        return [
            'pedidos' => $lista->take(self::POR_PAGINA)->map(fn (Order $o) => [
                'id' => $o->id,
                'total' => round((float) $o->total, 2),
                'status' => $o->status,
                'status_rotulo' => MetricasDaLoja::STATUS[$o->status] ?? ucfirst((string) $o->status),
                'criado_em' => $o->created_at?->toIso8601String(),
                'produto' => $o->items->first()?->product_name,
                'foto' => self::foto($o->items->first()),
                'itens' => (int) $o->items->sum('quantity'),
            ])->values()->all(),
            'pagina' => $pagina,
            'tem_mais' => $lista->count() > self::POR_PAGINA,
        ];
    }

    public function pedido(string $app, int $id): ?array
    {
        $o = $this->daLoja($app)->with(['items.product.images', 'payments', 'latestCorreiosPrePostagem', 'channelShipment', 'melhorEnvioEnvio'])->find($id);
        if (! $o) {
            return null;
        }
        $pagamento = $o->payments->sortByDesc('id')->first();

        return [
            'id' => $o->id,
            'status' => $o->status,
            'status_rotulo' => MetricasDaLoja::STATUS[$o->status] ?? ucfirst((string) $o->status),
            'pago' => in_array($o->status, ContributionMargin::REVENUE_STATUSES, true),
            'criado_em' => $o->created_at?->toIso8601String(),
            'cliente' => Str::before(trim((string) $o->shipping_name), ' ') ?: null,
            'cidade' => trim(($o->shipping_city ?? '').($o->shipping_state ? ' / '.$o->shipping_state : '')) ?: null,
            'pagamento' => $pagamento ? [
                'metodo' => match ($pagamento->method_type) {
                    Payment::METHOD_PIX => 'Pix',
                    Payment::METHOD_BOLETO => 'Boleto',
                    Payment::METHOD_CARD => 'Cartão',
                    default => ucfirst((string) $pagamento->method_type),
                },
            ] : null,
            'itens' => $o->items->map(fn (OrderItem $i) => [
                'nome' => (string) $i->product_name,
                'quantidade' => (int) $i->quantity,
                'preco' => round((float) $i->product_price, 2),
                'subtotal' => round((float) $i->subtotal, 2),
                'foto' => self::foto($i),
            ])->values()->all(),
            'subtotal' => round((float) $o->subtotal, 2),
            'frete' => round((float) $o->shipping_cost, 2),
            'desconto' => round((float) $o->discount_amount + (float) $o->pix_discount_amount, 2),
            'total' => round((float) $o->total, 2),
            'rastreio' => $o->trackingCode(),
            'cupom' => $o->coupon_code,
        ];
    }

    /** Catálogo do site da loja: kazakora.com (fora os só-FerraKora) ou os publicados na ferrakora.com. */
    public function produtos(string $app, ?string $busca): array
    {
        $fk = $app === AppsDeVendas::FERRAKORA;
        $titulos = $fk ? FerraKoraProduto::query()->get(['product_id', 'titulo', 'ativo', 'ordem'])->keyBy('product_id') : collect();

        $produtos = Product::query()
            ->whereNull('parent_product_id')
            ->when($fk, fn ($q) => $q->whereIn('id', $titulos->keys()), fn ($q) => $q->where('so_ferrakora', false))
            ->when(filled($busca), fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', '%'.$busca.'%')->orWhere('sku', 'like', '%'.$busca.'%')))
            ->with('images')
            ->orderByDesc('is_active')->orderBy('name')
            ->limit(300)
            ->get();

        return ['produtos' => $produtos->map(function (Product $p) use ($fk, $titulos) {
            $site = $titulos->get($p->id);

            return [
                'id' => $p->id,
                'nome' => $fk && filled($site?->titulo) ? (string) $site->titulo : (string) $p->name,
                'sku' => $p->sku,
                'preco' => round((float) $p->final_price, 2),
                'estoque' => (int) $p->stock,
                'ativo' => (bool) $p->is_active && (! $fk || (bool) $site?->ativo),
                'foto' => self::fotoDoProduto($p),
            ];
        })->sortByDesc('ativo')->values()->all()];
    }

    /**
     * Campanhas da conta Meta da loja no período (CampanhasMetaPorLoja, só
     * leitura) e, no topo da aba, o "Resultado do período" (bloco resultado).
     */
    public function campanhas(string $app, string $periodo): array
    {
        $loja = $app === AppsDeVendas::FERRAKORA ? CampanhasMetaPorLoja::FERRAKORA : CampanhasMetaPorLoja::KAZAKORA;
        if (! isset($this->metaPorLoja->lojas()[$loja])) {
            return ['configurada' => false, 'erro' => null, 'totais' => null, 'campanhas' => [],
                'resultado' => $this->resultado($app, $periodo, 0.0, null)];
        }

        $dados = $this->metaPorLoja->montar($loja, self::PERIODOS_META[$periodo] ?? 'this_month')[0] ?? null;
        $n = fn (array $m) => [
            'gasto' => isset($m['gasto']) ? round((float) $m['gasto'], 2) : null,
            'compras' => isset($m['compras']) ? (int) $m['compras'] : (isset($m['pedidos']) ? (int) $m['pedidos'] : null),
            'vendas' => isset($m['vendas']) ? round((float) $m['vendas'], 2) : null,
            'roas' => isset($m['roas']) ? round((float) $m['roas'], 2) : null,
        ];
        $totais = isset($dados['totais']) ? $n($dados['totais']) : null;
        $erro = $dados['erro'] ?? null;

        return [
            'configurada' => true,
            'erro' => $erro,
            'totais' => $totais,
            'resultado' => $this->resultado($app, $periodo, $erro === null ? (float) ($totais['gasto'] ?? 0) : null, $erro),
            'campanhas' => collect($dados['campanhas'] ?? [])
                ->reject(fn ($c) => (bool) ($c['oculta'] ?? false))
                ->map(fn (array $c) => [
                    'id' => (string) $c['id'],
                    'nome' => (string) $c['nome'],
                    'status' => (string) $c['status'],
                    'ativa' => $c['status'] === 'ACTIVE',
                    'orcamento_diario' => isset($c['orcamento_diario']) ? (float) $c['orcamento_diario'] : null,
                ] + $n($c))
                ->values()->all(),
        ];
    }

    /**
     * Dias que o date_preset da Meta cobre, para a loja usar a MESMA janela
     * que o gasto: [de, ate), ate exclusivo, dias inteiros. Atenção: na Meta,
     * last_7d e last_30d são os 7/30 dias ANTERIORES a hoje (hoje fora);
     * today e this_month incluem hoje.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function janelaDaMeta(string $periodo, ?Carbon $agora = null): array
    {
        $hoje = ($agora ?? now())->copy()->startOfDay();

        return match (self::PERIODOS_META[$periodo] ?? 'this_month') {
            'today' => [$hoje->copy(), $hoje->copy()->addDay()],
            'last_7d' => [$hoje->copy()->subDays(7), $hoje->copy()],
            'last_30d' => [$hoje->copy()->subDays(30), $hoje->copy()],
            default => [$hoje->copy()->startOfMonth(), $hoje->copy()->addDay()],
        };
    }

    /**
     * "O anúncio está pagando?" (pedido do Lira em 2026-11-21): a loja do app
     * (só o site, canal de MetricasDaLoja::CANAL) na mesma janela do gasto da
     * Meta. Cada número:
     *
     *  - gasto_meta: o mesmo de totais.gasto (insights da conta Meta da loja,
     *    lido ao vivo). Sem Meta configurada = 0; Meta com erro = null (e
     *    sobra/roas também null, para não mostrar conta errada).
     *  - faturamento_bruto: ContributionMargin::vendasBrutas() do canal: soma
     *    de orders.total (o que o cliente pagou, com frete) dos pedidos pagos,
     *    enviados ou entregues (REVENUE_STATUSES), só venda efetiva.
     *  - receita, custo_produtos, taxas, frete, imposto, bonificacoes: a linha
     *    do canal em ContributionMargin::conta() (= porCanal()), sem conta nova.
     *    Receita é a base da conta oficial: produtos menos descontos da loja,
     *    sem o frete pago pelo cliente (por isso é menor que o bruto).
     *  - valor_liquido: o lucro oficial ANTES do anúncio = lucro + ads da
     *    própria linha (= receita − custo − taxas − frete + bonificações −
     *    imposto). O lucro oficial já desconta o anúncio gravado em
     *    channel_ad_spends (Meta da KazaKora entra no canal loja, sincronizado
     *    pelo ads:sync-spend); devolvemos esse valor aqui para o gasto da Meta
     *    sair UMA vez só, logo abaixo, pelo número ao vivo.
     *  - anuncios_na_conta: o que a conta oficial tinha descontado de anúncio
     *    (informativo; pode ficar atrás da Meta até a próxima sincronização).
     *  - sobra_depois_do_anuncio: valor_liquido − gasto_meta.
     *  - roas_real: faturamento_bruto ÷ gasto_meta (null sem gasto).
     */
    public function resultado(string $app, string $periodo, ?float $gastoMeta, ?string $erroMeta = null): array
    {
        [$de, $ate] = self::janelaDaMeta($periodo);
        $canal = MetricasDaLoja::CANAL[$app];
        $conta = $this->margem->conta($de, $ate, $canal);
        $bruto = ContributionMargin::vendasBrutas($de, $ate, $canal);
        $liquido = round((float) $conta['lucro'] + (float) $conta['ads'], 2);
        $gasto = $gastoMeta !== null ? round($gastoMeta, 2) : null;

        return [
            'de' => $de->toDateString(),
            'ate' => $ate->copy()->subDay()->toDateString(),
            'pedidos' => (int) $conta['pedidos'],
            'gasto_meta' => $gasto,
            'meta_erro' => $erroMeta,
            'faturamento_bruto' => $bruto,
            'receita' => round((float) $conta['receita'], 2),
            'custo_produtos' => round((float) $conta['custo_produtos'], 2),
            'taxas' => round((float) $conta['taxas'], 2),
            'frete' => round((float) $conta['frete'], 2),
            'bonificacoes' => round((float) $conta['bonificacoes'], 2),
            'imposto' => round((float) $conta['imposto'], 2),
            'anuncios_na_conta' => round((float) $conta['ads'], 2),
            'valor_liquido' => $liquido,
            'sobra_depois_do_anuncio' => $gasto !== null ? round($liquido - $gasto, 2) : null,
            'roas_real' => $gasto !== null && $gasto > 0 ? round($bruto / $gasto, 2) : null,
        ];
    }

    private static function foto(?OrderItem $item): ?string
    {
        return $item?->product ? self::fotoDoProduto($item->product) : null;
    }

    private static function fotoDoProduto(Product $p): ?string
    {
        $img = $p->images->sortBy([['is_primary', 'desc'], ['position', 'asc']])->first();

        return $img?->thumb_url;
    }
}
