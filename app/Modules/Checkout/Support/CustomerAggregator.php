<?php

namespace App\Modules\Checkout\Support;

use App\Modules\Checkout\Models\Order;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Cliente" não é um cadastro próprio — é uma visão derivada de Order,
 * agrupada pelo documento (CPF/CNPJ) real do comprador. Pedido de loja
 * (origin=loja) sempre tem Order::user com cpf; pedido de canal externo
 * nunca tem user_id (é null por design em todo o módulo Marketplace), só
 * buyer_document. Agrupar pelos dois juntos (documento normalizado, só
 * dígitos) é o que permite a mesma pessoa que compra pelo site E pela
 * Shopee aparecer como um cliente só, em vez de dois. Pedido sem documento
 * nenhum (histórico raro, de antes de correções como
 * [[project-kazakora-status]] "buyer_cpf_id adicionado 2026-08-06") não
 * entra na lista — sem CPF/CNPJ não dá pra identificar quem comprou de
 * verdade, e inventar uma chave de agrupamento (por e-mail, por nome)
 * arriscaria juntar duas pessoas diferentes por engano.
 */
class CustomerAggregator
{
    /**
     * Só pedidos que representam uma venda real (nunca cancelado/nunca
     * chegou a pagar) contam pra "total gasto" e pro histórico do cliente
     * — um carrinho abandonado (status pending) ou um pedido cancelado não
     * é uma compra.
     */
    private const COUNTS_AS_PURCHASE = [
        Order::STATUS_PAID,
        Order::STATUS_SHIPPED,
        Order::STATUS_COMPLETED,
    ];

    /**
     * Colunas pelas quais a listagem pode ser ordenada (ver paginate()).
     */
    public const SORTABLE = ['name', 'orders_count', 'total_spent', 'last_purchase_at'];

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function list(): Collection
    {
        return $this->summaries()
            ->sortByDesc('last_purchase_at')
            ->values();
    }

    /**
     * Listagem paginada de /admin/clientes. Antes a tela recebia TODOS os
     * clientes, montados a partir de TODOS os pedidos com itens/pagamentos/
     * usuário hidratados como model (~2300 pedidos, 540 KB de HTML medido
     * em produção 2026-09-29). O agrupamento por documento continua em PHP
     * (normalizar CPF/CNPJ pra só dígitos e escolher "o dado mais recente
     * preenchido" não tem SQL equivalente igual em MySQL e SQLite), mas
     * agora em cima de linhas enxutas (summaries()), e só a página pedida
     * vai pro navegador.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(?string $search, ?string $sort, string $direction, int $perPage, int $page, string $path): LengthAwarePaginator
    {
        $customers = $this->summaries();

        $search = mb_strtolower(trim((string) $search));

        if ($search !== '') {
            $searchDigits = preg_replace('/\D/', '', $search) ?? '';

            $customers = $customers->filter(function (array $customer) use ($search, $searchDigits) {
                foreach (['name', 'email'] as $field) {
                    if (str_contains(mb_strtolower((string) $customer[$field]), $search)) {
                        return true;
                    }
                }

                // CPF/CNPJ e telefone: compara só dígitos, pra "123.456" e
                // "123456" acharem o mesmo cliente.
                return $searchDigits !== ''
                    && (str_contains($customer['document'], $searchDigits)
                        || str_contains(preg_replace('/\D/', '', (string) $customer['phone']) ?? '', $searchDigits));
            });
        }

        $sort = in_array($sort, self::SORTABLE, true) ? $sort : 'last_purchase_at';
        $descending = $direction !== 'asc';

        $customers = $customers
            ->sortBy(
                fn (array $customer) => $sort === 'name' ? mb_strtolower((string) $customer['name']) : $customer[$sort],
                $sort === 'name' ? SORT_NATURAL | SORT_FLAG_CASE : SORT_REGULAR,
                $descending,
            )
            ->values();

        return new LengthAwarePaginator(
            $customers->forPage($page, $perPage)->values(),
            $customers->count(),
            $perPage,
            $page,
            ['path' => $path, 'pageName' => 'page'],
        );
    }

    /**
     * @return array<string, mixed>|null null quando o documento não
     *                                    corresponde a nenhum pedido de venda real.
     */
    public function analytics(string $document): ?array
    {
        $document = preg_replace('/\D/', '', $document) ?? '';

        if ($document === '') {
            return null;
        }

        // Descobre os pedidos desse documento pelas linhas enxutas e só
        // então carrega itens/pagamentos DELES — antes carregava itens e
        // pagamentos de todos os pedidos da loja pra usar os de um cliente.
        $orderIds = $this->slimOrders()->where('document', $document)->pluck('id')->all();

        if ($orderIds === []) {
            return null;
        }

        $orders = $this->ordersWithDocument($orderIds)->where('document', $document);

        if ($orders->isEmpty()) {
            return null;
        }

        $summary = $this->summarize($orders);

        $products = $orders
            ->where('counts_as_purchase', true)
            ->flatMap(fn (array $order) => $order['items'])
            // product_id pode ser null (item de pedido manual sem produto
            // vinculado, ou produto excluído depois — a coluna é
            // nullOnDelete) — cai pro nome como chave de agrupamento pra
            // não juntar dois produtos diferentes só porque nenhum dos
            // dois tem product_id.
            ->groupBy(fn (array $item) => $item['product_id'] ?? "name:{$item['product_name']}")
            ->map(function (Collection $rows) {
                $first = $rows->first();

                return [
                    'product_id' => $first['product_id'],
                    'product_name' => $first['product_name'],
                    'quantity' => $rows->sum('quantity'),
                    'total_spent' => round((float) $rows->sum('subtotal'), 2),
                ];
            })
            ->sortByDesc('total_spent')
            ->values();

        $orderRows = $orders
            ->sortByDesc('created_at')
            ->map(fn (array $order) => [
                'id' => $order['id'],
                'origin' => $order['origin'],
                'status' => $order['status'],
                'total' => $order['total'],
                'payment_method' => $order['payment_method'],
                'created_at' => $order['created_at'],
                'items' => $order['items'],
            ])
            ->values();

        return [
            'customer' => $summary,
            'products' => $products,
            'orders' => $orderRows,
        ];
    }

    /**
     * Uma linha por pedido com só o que a listagem precisa (sem itens,
     * pagamentos nem model Eloquent) — base de list()/paginate() e da busca
     * dos pedidos de um cliente em analytics(). Mesma regra de documento de
     * ordersWithDocument(): buyer_document, senão o CPF do usuário da loja
     * (usuário excluído conta como sem usuário, igual ao eager-load).
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function slimOrders(): Collection
    {
        return DB::table('orders')
            ->leftJoin('users', function ($join) {
                $join->on('users.id', '=', 'orders.user_id')->whereNull('users.deleted_at');
            })
            ->select([
                'orders.id', 'orders.origin', 'orders.status', 'orders.total', 'orders.created_at',
                'orders.buyer_document', 'orders.shipping_name', 'orders.shipping_email', 'orders.shipping_phone',
                'users.cpf as user_cpf', 'users.name as user_name', 'users.email as user_email', 'users.phone as user_phone',
            ])
            ->get()
            ->map(function (object $order) {
                $document = preg_replace('/\D/', '', (string) ($order->buyer_document ?: $order->user_cpf ?? ''));

                if ($document === '') {
                    return null;
                }

                return [
                    'document' => $document,
                    'id' => $order->id,
                    'origin' => $order->origin,
                    'status' => $order->status,
                    'total' => (float) $order->total,
                    // String 'Y-m-d H:i:s' — ordena/compara igual a data, e
                    // só vira Carbon no resumo final (summarize()).
                    'created_at' => $order->created_at,
                    'counts_as_purchase' => in_array($order->status, self::COUNTS_AS_PURCHASE, true),
                    'name' => $order->shipping_name ?: $order->user_name,
                    'email' => $order->shipping_email ?: $order->user_email,
                    'phone' => $order->shipping_phone ?: $order->user_phone,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function summaries(): Collection
    {
        return $this->slimOrders()
            ->groupBy('document')
            ->map(fn (Collection $orders) => $this->summarize($orders))
            ->values();
    }

    /**
     * Uma linha "achatada" por pedido, já com o documento normalizado e o
     * modo de pagamento resolvido — usada no analytics() de um cliente
     * (só os pedidos dele, ver analytics()).
     *
     * @param  array<int, int>  $orderIds
     * @return Collection<int, array<string, mixed>>
     */
    private function ordersWithDocument(array $orderIds): Collection
    {
        return Order::query()
            ->whereIn('id', $orderIds)
            ->with(['user:id,name,email,phone,cpf', 'items:id,order_id,product_id,product_name,product_price,quantity,subtotal', 'payments:id,order_id,method_type,status'])
            ->get()
            ->map(function (Order $order) {
                $document = preg_replace('/\D/', '', (string) ($order->buyer_document ?: $order->user?->cpf ?? ''));

                if ($document === '') {
                    return null;
                }

                return [
                    'document' => $document,
                    'id' => $order->id,
                    'origin' => $order->origin,
                    'status' => $order->status,
                    'total' => (float) $order->total,
                    'created_at' => $order->created_at,
                    'counts_as_purchase' => in_array($order->status, self::COUNTS_AS_PURCHASE, true),
                    'name' => $order->shipping_name ?: $order->user?->name,
                    'email' => $order->shipping_email ?: $order->user?->email,
                    'phone' => $order->shipping_phone ?: $order->user?->phone,
                    'payment_method' => $this->resolvePaymentMethod($order),
                    'items' => $order->items->map(fn ($item) => [
                        'product_id' => $item->product_id,
                        'product_name' => $item->product_name,
                        'quantity' => $item->quantity,
                        'unit_price' => (float) $item->product_price,
                        'subtotal' => (float) $item->subtotal,
                    ])->all(),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Só pedido de loja tem Payment (Stripe) — canal externo processa o
     * pagamento do lado de lá, o Kazakora nunca vê o método real, então
     * não inventa um aqui (ver princípio geral do projeto contra dado
     * fabricado, mesmo já aplicado em NFeXmlBuilderService/PDP). Split
     * payment (2 métodos no mesmo pedido) aparece com os dois separados
     * por "+".
     */
    private function resolvePaymentMethod(Order $order): ?string
    {
        $labels = [
            'card' => 'Cartão',
            'pix' => 'Pix',
            'boleto' => 'Boleto',
        ];

        $methods = $order->payments
            ->whereIn('status', ['authorized', 'captured'])
            ->pluck('method_type')
            ->map(fn ($method) => $labels[$method] ?? $method)
            ->unique();

        return $methods->isEmpty() ? null : $methods->implode(' + ');
    }

    /**
     * @return array<string, mixed>
     */
    private function summarize(Collection $orders): array
    {
        // Nome/e-mail/telefone: usa o pedido mais recente que tem o campo
        // preenchido — um pedido antigo com dado incompleto (ex: import
        // anterior à correção de mascaramento da Shopee, ver
        // [[project-kazakora-status]]) não deve esconder um dado bom que
        // um pedido mais novo já tem.
        $latestWithField = function (string $field) use ($orders) {
            $match = $orders->sortByDesc('created_at')->first(fn (array $order) => filled($order[$field] ?? null));

            return $match[$field] ?? null;
        };

        $purchases = $orders->where('counts_as_purchase', true);

        return [
            'document' => $orders->first()['document'],
            'name' => $latestWithField('name'),
            'email' => $latestWithField('email'),
            'phone' => $latestWithField('phone'),
            'origins' => $orders->pluck('origin')->unique()->values()->all(),
            'orders_count' => $purchases->count(),
            'total_spent' => round((float) $purchases->sum('total'), 2),
            'first_purchase_at' => $this->asDate($purchases->min('created_at')),
            'last_purchase_at' => $this->asDate($purchases->max('created_at') ?? $orders->max('created_at')),
        ];
    }

    /**
     * Linha enxuta traz a data como string do banco; linha de model já vem
     * Carbon. Normaliza pra Carbon nos dois casos — a tela recebe sempre o
     * mesmo formato ISO de antes.
     */
    private function asDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof Carbon ? $value : Carbon::parse($value);
    }
}
