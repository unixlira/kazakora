<?php

namespace App\Modules\Checkout\Support;

use App\Modules\Checkout\Models\Order;
use Illuminate\Support\Collection;

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
     * @return Collection<int, array<string, mixed>>
     */
    public function list(): Collection
    {
        return $this->ordersWithDocument()
            ->groupBy('document')
            ->map(fn (Collection $orders) => $this->summarize($orders))
            ->sortByDesc('last_purchase_at')
            ->values();
    }

    /**
     * @return array<string, mixed>|null null quando o documento não
     *                                    corresponde a nenhum pedido de venda real.
     */
    public function analytics(string $document): ?array
    {
        $document = preg_replace('/\D/', '', $document) ?? '';

        $orders = $this->ordersWithDocument()->where('document', $document);

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
     * Uma linha "achatada" por pedido, já com o documento normalizado e o
     * modo de pagamento resolvido — base tanto pra list() quanto pra
     * analytics(), pra não duplicar a query/lógica de agrupamento.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function ordersWithDocument(): Collection
    {
        return Order::query()
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
            'first_purchase_at' => $purchases->min('created_at'),
            'last_purchase_at' => $purchases->max('created_at') ?? $orders->max('created_at'),
        ];
    }
}
