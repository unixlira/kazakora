<?php

namespace App\Modules\Checkout\Support;

use App\Models\Setting;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Order bump do checkout da KazaKora (pedido do Lira, 2026-11-24: "criar order
 * bump, melhor coisa no checkout, ver se aumenta o ticket"): uma caixa antes do
 * "Finalizar pedido" oferecendo UM produto a mais, com desconto só nesta compra.
 *
 * Qual produto, nesta ordem (o primeiro que servir):
 * 1. Escolhido no cadastro de um produto do carrinho (order_bump_product_id,
 *    com o % de order_bump_desconto_pct; variação sem escolha usa a do pai).
 * 2. Produto geral da tela Admin > Order bump (Setting order_bump.produto_id).
 * 3. Automático: produto ativo da KazaKora (naKazaKora), com estoque e custo
 *    cadastrado, fora do carrinho (nem variação do mesmo anúncio), com preço
 *    até 35% do subtotal (nunca menos que R$ 20 de teto, para carrinho barato
 *    também ter oferta); o mais vendido nos últimos 90 dias, empate pelo mais
 *    barato.
 * Nada serve = sem caixa.
 *
 * Preço: preço da loja de 1 unidade (final_price, já com o frete embutido)
 * menos o desconto do bump (padrão 15%, configurável). Margem mínima: o preço
 * do bump menos o frete embutido dele nunca fica abaixo do custo + 30%; se
 * ficar, o desconto encolhe até esse piso; se nem o preço cheio passa do piso
 * (ou o produto não tem custo cadastrado), não oferece.
 *
 * O preço é sempre calculado aqui, no servidor: o navegador só diz "quero o
 * produto X" e o pedido conta de novo (CheckoutController::storePayment).
 *
 * Frete: o bump vai na mesma caixa e NÃO entra na conta de frete do carrinho
 * (diferença do CEP, desconto da plataforma, Entrega Expressa): o frete
 * embutido do próprio bump fica no preço dele e cobre o peso a mais. Assim
 * marcar a caixa nunca faz aparecer cobrança de frete.
 *
 * Cupom e Pix: o bump já tem desconto próprio, então não entra na base do
 * cupom (BLACKFRIDAY e outros) nem do desconto no Pix; esses continuam
 * calculados só sobre os produtos do carrinho.
 */
class OrderBump
{
    public const CHAVE_LIGADO = 'order_bump.ligado';

    public const CHAVE_PRODUTO = 'order_bump.produto_id';

    public const CHAVE_DESCONTO = 'order_bump.desconto_pct';

    public const DESCONTO_PADRAO = 15.0;

    /** Preço do bump (sem o frete embutido) >= custo × 1,30. */
    public const MARGEM_MINIMA = 0.30;

    /** Automático: preço até 35% do subtotal do carrinho... */
    public const TETO_DO_SUBTOTAL = 0.35;

    /** ...mas o teto nunca fica abaixo disto (carrinho barato também tem oferta). */
    public const TETO_MINIMO = 20.0;

    public const DIAS_DO_RANKING = 90;

    public const ESCOLHA_PRODUTO = 'produto';

    public const ESCOLHA_GERAL = 'geral';

    public const ESCOLHA_AUTOMATICA = 'automatico';

    public const EVENTO_EXIBIDO = 'exibido';

    public const EVENTO_MARCOU = 'marcou';

    public const EVENTO_DESMARCOU = 'desmarcou';

    /** Exibições já gravadas nesta sessão (uma por produto). */
    private const SESSAO_EXIBIDOS = 'checkout.order_bump_exibidos';

    public function ligado(): bool
    {
        return Setting::get(self::CHAVE_LIGADO, '1') !== '0';
    }

    public function descontoPadrao(): float
    {
        $valor = Setting::get(self::CHAVE_DESCONTO);

        return $valor === null || $valor === '' ? self::DESCONTO_PADRAO : max(0.0, min(90.0, (float) $valor));
    }

    public function produtoGeralId(): ?int
    {
        $id = (int) Setting::get(self::CHAVE_PRODUTO);

        return $id > 0 ? $id : null;
    }

    public function configurar(bool $ligado, ?int $produtoId, float $desconto): void
    {
        Setting::set(self::CHAVE_LIGADO, $ligado ? '1' : '0');
        Setting::set(self::CHAVE_PRODUTO, $produtoId ? (string) $produtoId : null);
        Setting::set(self::CHAVE_DESCONTO, number_format(max(0, min(90, $desconto)), 2, '.', ''));
    }

    /**
     * A oferta para este carrinho, ou null.
     *
     * @param  Collection<int, array{product: Product, quantity: int, subtotal: float}>  $cartItems
     * @return array{product: Product, preco_de: float, preco: float, desconto_pct: float, escolha: string}|null
     */
    public function oferta(Collection $cartItems): ?array
    {
        if (! $this->ligado() || $cartItems->isEmpty()) {
            return null;
        }

        $grupo = $this->grupoDoCarrinho($cartItems);
        $padrao = $this->descontoPadrao();

        // 1. Escolha do cadastro, começando pelo item que mais pesa no carrinho.
        $paisIds = $cartItems->map(fn ($item) => $item['product']->parent_product_id)->filter()->unique()->values();
        $pais = $paisIds->isEmpty() ? collect() : Product::query()->whereIn('id', $paisIds)->get(['id', 'order_bump_product_id', 'order_bump_desconto_pct'])->keyBy('id');

        foreach ($cartItems->sortByDesc('subtotal') as $item) {
            $product = $item['product'];
            $pai = $product->parent_product_id ? $pais->get($product->parent_product_id) : null;
            $alvo = $product->order_bump_product_id ?: $pai?->order_bump_product_id;
            if (! $alvo) {
                continue;
            }
            $pct = $product->order_bump_product_id ? $product->order_bump_desconto_pct : $pai?->order_bump_desconto_pct;
            $oferta = $this->montar($this->buscar($alvo), $grupo, $pct !== null ? (float) $pct : $padrao, self::ESCOLHA_PRODUTO);
            if ($oferta) {
                return $oferta;
            }
        }

        // 2. Produto geral do admin.
        if ($geral = $this->produtoGeralId()) {
            if ($oferta = $this->montar($this->buscar($geral), $grupo, $padrao, self::ESCOLHA_GERAL)) {
                return $oferta;
            }
        }

        // 3. Automático.
        $subtotal = (float) $cartItems->sum('subtotal');
        $teto = max(self::TETO_MINIMO, round($subtotal * self::TETO_DO_SUBTOTAL, 2));

        foreach ($this->candidatosAutomaticos($grupo, $teto) as $candidato) {
            if ($candidato->unitPriceForQuantity(1) > $teto) {
                continue;
            }
            if ($oferta = $this->montar($candidato, $grupo, $padrao, self::ESCOLHA_AUTOMATICA)) {
                return $oferta;
            }
        }

        return null;
    }

    /**
     * A oferta que o cliente marcou, conferida de novo agora (pedido). Null se
     * não marcou; false se marcou um produto que não é mais a oferta.
     *
     * @return array{product: Product, preco_de: float, preco: float, desconto_pct: float, escolha: string}|false|null
     */
    public function confirmar(Collection $cartItems, mixed $productId): array|false|null
    {
        $id = (int) $productId;
        if ($id <= 0) {
            return null;
        }

        $oferta = $this->oferta($cartItems);

        return $oferta && (int) $oferta['product']->id === $id ? $oferta : false;
    }

    /**
     * O que a caixa do checkout mostra.
     *
     * @return array<string, mixed>|null
     */
    public function paraTela(?array $oferta): ?array
    {
        if (! $oferta) {
            return null;
        }

        /** @var Product $product */
        $product = $oferta['product'];
        $imagens = $product->relationLoaded('images') ? $product->images : $product->images()->get();
        $foto = $imagens->firstWhere('is_primary', true) ?? $imagens->first();
        if (! $foto && $product->parent_product_id) {
            $foto = $product->parent?->images()->orderByDesc('is_primary')->orderBy('position')->first();
        }
        $destaques = $product->adContent()->value('destaques') ?? ($product->parent_product_id ? $product->parent?->adContent()->value('destaques') : null);
        $destaques = is_string($destaques) ? json_decode($destaques, true) : $destaques;

        return [
            'product_id' => (int) $product->id,
            'nome' => Str::limit($product->name, 70),
            'variacao' => $product->variation,
            'imagem' => $foto?->thumb_url ?? $foto?->url,
            'preco_de' => $oferta['preco_de'],
            'preco' => $oferta['preco'],
            'desconto_pct' => $oferta['desconto_pct'],
            'economia' => round($oferta['preco_de'] - $oferta['preco'], 2),
            'beneficio' => is_array($destaques) && filled($destaques[0] ?? null) ? Str::limit((string) $destaques[0], 90) : null,
        ];
    }

    /**
     * Exibição, marcou ou desmarcou. Exibição conta uma vez por sessão e
     * produto. Sem dado pessoal: só o hash da sessão.
     */
    public function registrar(Request $request, string $tipo, int $productId, ?float $preco = null): void
    {
        if ($tipo === self::EVENTO_EXIBIDO) {
            $vistos = (array) $request->session()->get(self::SESSAO_EXIBIDOS, []);
            if (in_array($productId, $vistos, true)) {
                return;
            }
            $request->session()->put(self::SESSAO_EXIBIDOS, [...$vistos, $productId]);
        }

        rescue(fn () => DB::table('order_bump_eventos')->insert([
            'tipo' => $tipo,
            'product_id' => $productId,
            'sessao' => substr(hash('sha256', (string) $request->session()->getId()), 0, 40),
            'preco' => $preco,
            'created_at' => now(),
        ]));
    }

    /**
     * Números da tela Admin > Order bump no período: exibições e aceites
     * (sessões), pedidos com bump, receita extra e ticket médio com e sem bump
     * (pedidos pagos da loja KazaKora).
     *
     * @return array<string, mixed>
     */
    public function resumo(\DateTimeInterface $de, \DateTimeInterface $ate): array
    {
        $eventos = DB::table('order_bump_eventos')->whereBetween('created_at', [$de, $ate]);
        $exibicoes = (clone $eventos)->where('tipo', self::EVENTO_EXIBIDO)->distinct()->count('sessao');
        $marcaram = (clone $eventos)->where('tipo', self::EVENTO_MARCOU)->distinct()->count('sessao');

        $pagos = [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED];
        $pedidos = Order::query()
            ->where('origin', Order::ORIGIN_STORE)
            ->whereNull('site_origem')
            ->whereIn('status', $pagos)
            ->whereBetween('created_at', [$de, $ate]);

        $comBump = (clone $pedidos)->whereHas('items', fn ($q) => $q->where('origem', OrderItem::ORIGEM_ORDER_BUMP));
        $semBump = (clone $pedidos)->whereDoesntHave('items', fn ($q) => $q->where('origem', OrderItem::ORIGEM_ORDER_BUMP));
        $qtdCom = (clone $comBump)->count();
        $qtdSem = (clone $semBump)->count();

        $receitaExtra = (float) OrderItem::query()
            ->where('origem', OrderItem::ORIGEM_ORDER_BUMP)
            ->whereIn('order_id', (clone $pedidos)->select('id'))
            ->sum('subtotal');

        $porProduto = OrderItem::query()
            ->where('origem', OrderItem::ORIGEM_ORDER_BUMP)
            ->whereIn('order_id', (clone $pedidos)->select('id'))
            ->groupBy('product_id', 'product_name')
            ->selectRaw('product_id, product_name, count(*) as pedidos, sum(subtotal) as receita')
            ->orderByDesc('pedidos')
            ->limit(10)
            ->get()
            ->map(fn ($linha) => ['product_id' => $linha->product_id, 'nome' => $linha->product_name, 'pedidos' => (int) $linha->pedidos, 'receita' => round((float) $linha->receita, 2)])
            ->all();

        return [
            'exibicoes' => $exibicoes,
            'aceites' => $marcaram,
            'taxa_aceite' => $exibicoes > 0 ? round($marcaram / $exibicoes * 100, 1) : 0.0,
            'pedidos_com_bump' => $qtdCom,
            'pedidos_sem_bump' => $qtdSem,
            'taxa_pedidos' => ($qtdCom + $qtdSem) > 0 ? round($qtdCom / ($qtdCom + $qtdSem) * 100, 1) : 0.0,
            'receita_extra' => round($receitaExtra, 2),
            'ticket_com_bump' => $qtdCom > 0 ? round((float) (clone $comBump)->avg('total'), 2) : 0.0,
            'ticket_sem_bump' => $qtdSem > 0 ? round((float) (clone $semBump)->avg('total'), 2) : 0.0,
            'ticket_geral' => ($qtdCom + $qtdSem) > 0 ? round((float) (clone $pedidos)->avg('total'), 2) : 0.0,
            'por_produto' => $porProduto,
        ];
    }

    /**
     * Ids do carrinho e dos anúncios pai deles: o bump nunca é o mesmo
     * produto nem outra variação do mesmo anúncio.
     *
     * @return list<int>
     */
    private function grupoDoCarrinho(Collection $cartItems): array
    {
        return $cartItems->flatMap(fn ($item) => [(int) $item['product']->id, (int) $item['product']->parent_product_id])
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function buscar(int $id): ?Product
    {
        return Product::query()->with('quantityDiscounts', 'images')->find($id);
    }

    /**
     * Mais vendidos (90 dias, pedidos pagos de todos os canais) que cabem no
     * carrinho, empate pelo mais barato. O teto aqui é só um pré-filtro pelo
     * preço sem frete; a conta exata (com frete embutido) é feita em oferta().
     *
     * @param  list<int>  $grupo
     * @return Collection<int, Product>
     */
    private function candidatosAutomaticos(array $grupo, float $teto): Collection
    {
        $ranking = $this->rankingDeVendas();

        return Product::query()
            ->where('products.is_active', true)
            ->where('products.so_ferrakora', false)
            ->where('products.stock', '>', 0)
            ->where('products.cost_price', '>', 0)
            ->whereNotIn('products.id', $grupo ?: [0])
            ->where(fn ($q) => $q->whereNull('products.parent_product_id')->orWhereNotIn('products.parent_product_id', $grupo ?: [0]))
            ->whereRaw('(products.price * (1 - coalesce(products.discount_percentage, 0) / 100) - coalesce(products.discount_amount, 0)) <= ?', [$teto])
            ->with('quantityDiscounts', 'images')
            ->get()
            ->sortBy([
                fn (Product $a, Product $b) => ($ranking[$b->id] ?? 0) <=> ($ranking[$a->id] ?? 0),
                fn (Product $a, Product $b) => $a->unitPriceForQuantity(1) <=> $b->unitPriceForQuantity(1),
                fn (Product $a, Product $b) => $a->id <=> $b->id,
            ])
            ->take(60)
            ->values();
    }

    /**
     * Unidades vendidas por produto nos últimos 90 dias (pedidos pagos de
     * todos os canais), guardado 30 minutos: o checkout abre e recalcula o
     * frete várias vezes por visita.
     *
     * @return array<int, int>
     */
    private function rankingDeVendas(): array
    {
        return Cache::remember('order_bump.ranking', now()->addMinutes(30), fn () => DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.status', [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED])
            ->where('orders.created_at', '>=', now()->subDays(self::DIAS_DO_RANKING))
            ->whereNotNull('order_items.product_id')
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id, sum(order_items.quantity) as vendidos')
            ->pluck('vendidos', 'product_id')
            ->map(fn ($vendidos) => (int) $vendidos)
            ->all());
    }

    /**
     * @param  list<int>  $grupo
     * @return array{product: Product, preco_de: float, preco: float, desconto_pct: float, escolha: string}|null
     */
    private function montar(?Product $product, array $grupo, float $descontoPct, string $escolha): ?array
    {
        if (! $product || ! $product->naKazaKora() || (int) $product->stock < 1) {
            return null;
        }
        if (in_array((int) $product->id, $grupo, true) || ($product->parent_product_id && in_array((int) $product->parent_product_id, $grupo, true))) {
            return null;
        }

        $custo = (float) $product->cost_price;
        if ($custo <= 0) {
            return null;
        }

        $precoDe = round($product->unitPriceForQuantity(1), 2);
        // Piso: custo + 30% mais o frete embutido do próprio bump (que fica no preço dele).
        $piso = ceil(round(($custo * (1 + self::MARGEM_MINIMA) + $product->freteEmbutido()) * 100, 4)) / 100;
        if ($precoDe <= 0 || $precoDe < $piso) {
            return null;
        }

        $descontoPct = max(0.0, min(90.0, $descontoPct));
        $preco = max($piso, round($precoDe * (1 - $descontoPct / 100), 2));
        $preco = min($preco, $precoDe);

        return [
            'product' => $product,
            'preco_de' => $precoDe,
            'preco' => round($preco, 2),
            'desconto_pct' => round((1 - $preco / $precoDe) * 100, 1),
            'escolha' => $escolha,
        ];
    }
}
