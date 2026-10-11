<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cadastros\Models\Supplier;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockManager;
use App\Modules\Operacional\Models\PurchaseOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    public function __construct(private readonly StockManager $stock) {}

    public function index(): Response
    {
        return Inertia::render('Admin/PurchaseOrders/Index', [
            'purchaseOrders' => PurchaseOrder::query()
                ->with(['supplier:id,name', 'items'])
                ->latest()
                ->get()
                ->map(fn (PurchaseOrder $order) => [
                    ...$order->toArray(),
                    'total' => $order->total(),
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/PurchaseOrders/Create', [
            'suppliers' => Supplier::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'products' => Product::query()->orderBy('name')->get(['id', 'name', 'sku']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'expected_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);

        $purchaseOrder = PurchaseOrder::create([
            'supplier_id' => $validated['supplier_id'],
            'expected_date' => $validated['expected_date'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'status' => PurchaseOrder::STATUS_DRAFT,
            'created_by' => $request->user()->id,
        ]);

        $purchaseOrder->items()->createMany($validated['items']);

        return redirect()->route('admin.pedidos-de-compra.listar')->with('success', 'Pedido de compra criado.');
    }

    public function show(PurchaseOrder $purchaseOrder): Response
    {
        return Inertia::render('Admin/PurchaseOrders/Show', [
            'purchaseOrder' => [
                ...$purchaseOrder->load(['supplier', 'items.product', 'creator:id,name'])->toArray(),
                'total' => $purchaseOrder->total(),
            ],
        ]);
    }

    public function updateStatus(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([PurchaseOrder::STATUS_DRAFT, PurchaseOrder::STATUS_SENT, PurchaseOrder::STATUS_CANCELLED])],
        ]);

        $purchaseOrder->update($validated);

        return back()->with('success', 'Status do pedido de compra atualizado.');
    }

    public function receive(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        if ($purchaseOrder->status === PurchaseOrder::STATUS_RECEIVED) {
            return back()->with('warning', 'Este pedido de compra já foi recebido.');
        }

        foreach ($purchaseOrder->items as $item) {
            $this->stock->adjust(
                $item->product,
                $item->quantity,
                StockMovement::TYPE_RESTOCK,
                reason: "Recebimento do pedido de compra #{$purchaseOrder->id}",
                reference: $purchaseOrder,
            );
        }

        $purchaseOrder->update(['status' => PurchaseOrder::STATUS_RECEIVED, 'received_at' => now()]);

        return back()->with('success', 'Pedido de compra recebido e estoque atualizado.');
    }

    public function destroy(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        if ($purchaseOrder->status === PurchaseOrder::STATUS_RECEIVED) {
            return back()->with('error', 'Não é possível excluir um pedido de compra já recebido.');
        }

        $purchaseOrder->delete();

        return back()->with('success', 'Pedido de compra removido.');
    }
}
