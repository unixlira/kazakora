<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Modules\Checkout\Models\Order;
use App\Notifications\OrderStatusUpdated;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * API pública, pedido explícito 2026-08-21. `updateStatus()` cobre só a
 * transição de status + notificação do cliente — DE PROPÓSITO não
 * reimplementa aqui os efeitos colaterais de CANCELAMENTO que o painel
 * admin tem (estorno real no Stripe, devolução de estoque, cancelamento
 * de NF-e autorizada — ver Admin\OrderController::update()). Duplicar
 * essa lógica financeira/fiscal sob pressão de prazo é exatamente o tipo
 * de risco que não vale a pena correr — cancelamento continua sendo feito
 * só pelo painel admin por enquanto. status=cancelled é REJEITADO aqui de
 * propósito (ver validação), não silenciosamente incompleto.
 */
class OrderController extends Controller
{
    private const UPDATABLE_STATUSES = [
        Order::STATUS_PAID,
        Order::STATUS_SHIPPED,
        Order::STATUS_COMPLETED,
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in([
                Order::STATUS_PENDING,
                Order::STATUS_AWAITING_PAYMENT,
                Order::STATUS_PAID,
                Order::STATUS_SHIPPED,
                Order::STATUS_COMPLETED,
                Order::STATUS_CANCELLED,
            ])],
            'origin' => ['nullable', 'string'],
            'external_order_id' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $orders = Order::query()
            ->with(['items', 'invoice', 'channelShipment'])
            ->when($validated['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($validated['origin'] ?? null, fn ($query, $origin) => $query->where('origin', $origin))
            ->when($validated['external_order_id'] ?? null, fn ($query, $id) => $query->where('external_order_id', $id))
            ->latest('id')
            ->paginate($validated['per_page'] ?? 25);

        return OrderResource::collection($orders);
    }

    public function show(Order $order): OrderResource
    {
        return new OrderResource($order->load(['items', 'invoice', 'channelShipment']));
    }

    public function updateStatus(Request $request, Order $order): OrderResource
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(self::UPDATABLE_STATUSES)],
        ]);

        $changed = $order->status !== $validated['status'];

        $order->update($validated);

        if ($changed && $order->user) {
            $order->user->notify(new OrderStatusUpdated($order));
        }

        return new OrderResource($order->fresh(['items', 'invoice', 'channelShipment']));
    }
}
