<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Services\InvoiceService;
use App\Notifications\OrderStatusUpdated;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class OrderController extends Controller
{
    private const STATUSES = [
        Order::STATUS_PENDING,
        Order::STATUS_PAID,
        Order::STATUS_SHIPPED,
        Order::STATUS_COMPLETED,
        Order::STATUS_CANCELLED,
    ];

    public function index(): Response
    {
        return Inertia::render('Admin/Orders/Index', [
            'orders' => Order::query()->with('user:id,name,email')->withCount('items')->latest()->get(),
            'statuses' => self::STATUSES,
        ]);
    }

    public function show(Order $order): Response
    {
        return Inertia::render('Admin/Orders/Show', [
            'order' => $order->load(['items', 'user:id,name,email']),
            'statuses' => self::STATUSES,
        ]);
    }

    public function update(Request $request, Order $order, InvoiceService $invoices): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
        ]);

        $statusChanged = $order->status !== $validated['status'];

        $order->update($validated);

        if ($statusChanged && $order->user) {
            $order->user->notify(new OrderStatusUpdated($order));
        }

        $invoiceWarning = null;

        if ($statusChanged && $validated['status'] === Order::STATUS_CANCELLED) {
            $invoiceWarning = $this->cancelInvoiceIfAuthorized($order, $invoices);
        }

        $response = back()->with('success', 'Status do pedido atualizado.');

        return $invoiceWarning ? $response->with('warning', $invoiceWarning) : $response;
    }

    /**
     * Cancela a NF-e do pedido junto (Etapa 5) quando ele tem uma nota
     * autorizada. Nunca bloqueia a mudança de status do pedido em si — se o
     * cancelamento na SEFAZ falhar (ex: prazo de 24h expirado), o admin
     * precisa ser avisado pra tratar manualmente, mas o pedido já foi
     * marcado como cancelado de qualquer forma.
     */
    private function cancelInvoiceIfAuthorized(Order $order, InvoiceService $invoices): ?string
    {
        $order->loadMissing('invoice');

        if (! $order->invoice || $order->invoice->status !== Invoice::STATUS_AUTHORIZED) {
            return null;
        }

        try {
            $invoices->cancel($order, "Cancelamento do pedido #{$order->id}");

            return null;
        } catch (Throwable $exception) {
            Log::error('nfe.cancel.failed', ['order_id' => $order->id, 'message' => $exception->getMessage()]);

            return "Pedido cancelado, mas a NF-e não pôde ser cancelada automaticamente na SEFAZ: {$exception->getMessage()}";
        }
    }
}
