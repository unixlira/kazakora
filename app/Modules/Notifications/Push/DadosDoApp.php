<?php

namespace App\Modules\Notifications\Push;

use App\Modules\Admin\Support\Publicidade\CampanhasMetaPorLoja;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Checkout\Models\Payment;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use App\Modules\Marketplace\Support\ContributionMargin;
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

    public function __construct(private readonly CampanhasMetaPorLoja $metaPorLoja) {}

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

    /** Campanhas da conta Meta da loja no período (CampanhasMetaPorLoja, só leitura). */
    public function campanhas(string $app, string $periodo): array
    {
        $loja = $app === AppsDeVendas::FERRAKORA ? CampanhasMetaPorLoja::FERRAKORA : CampanhasMetaPorLoja::KAZAKORA;
        if (! isset($this->metaPorLoja->lojas()[$loja])) {
            return ['configurada' => false, 'erro' => null, 'totais' => null, 'campanhas' => []];
        }

        $dados = $this->metaPorLoja->montar($loja, self::PERIODOS_META[$periodo] ?? 'this_month')[0] ?? null;
        $n = fn (array $m) => [
            'gasto' => isset($m['gasto']) ? round((float) $m['gasto'], 2) : null,
            'compras' => isset($m['compras']) ? (int) $m['compras'] : (isset($m['pedidos']) ? (int) $m['pedidos'] : null),
            'vendas' => isset($m['vendas']) ? round((float) $m['vendas'], 2) : null,
            'roas' => isset($m['roas']) ? round((float) $m['roas'], 2) : null,
        ];

        return [
            'configurada' => true,
            'erro' => $dados['erro'] ?? null,
            'totais' => isset($dados['totais']) ? $n($dados['totais']) : null,
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
