<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Operacional\Models\ServiceOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ServiceOrderController extends Controller
{
    private const STATUSES = [
        ServiceOrder::STATUS_OPEN,
        ServiceOrder::STATUS_IN_PROGRESS,
        ServiceOrder::STATUS_COMPLETED,
        ServiceOrder::STATUS_CANCELLED,
    ];

    public function index(): Response
    {
        return Inertia::render('Admin/ServiceOrders/Index', [
            'serviceOrders' => ServiceOrder::query()->with('assignee:id,name')->latest()->get(),
            'staff' => User::query()->whereIn('role', User::STAFF_ROLES)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/ServiceOrders/Create', [
            'staff' => User::query()->whereIn('role', User::STAFF_ROLES)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);
        $validated['created_by'] = $request->user()->id;
        $validated['status'] = ServiceOrder::STATUS_OPEN;

        ServiceOrder::create($validated);

        return redirect()->route('admin.ordens-de-servico.listar')->with('success', 'Ordem de serviço criada.');
    }

    public function edit(ServiceOrder $serviceOrder): Response
    {
        return Inertia::render('Admin/ServiceOrders/Edit', [
            'serviceOrder' => $serviceOrder,
            'staff' => User::query()->whereIn('role', User::STAFF_ROLES)->orderBy('name')->get(['id', 'name']),
            'statuses' => self::STATUSES,
        ]);
    }

    public function update(Request $request, ServiceOrder $serviceOrder): RedirectResponse
    {
        $validated = $this->validated($request);
        $validated['status'] = $request->validate(['status' => ['required', Rule::in(self::STATUSES)]])['status'];

        $serviceOrder->update($validated);

        return redirect()->route('admin.ordens-de-servico.listar')->with('success', 'Ordem de serviço atualizada.');
    }

    public function destroy(ServiceOrder $serviceOrder): RedirectResponse
    {
        $serviceOrder->delete();

        return back()->with('success', 'Ordem de serviço removida.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_contact' => ['nullable', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'assigned_to' => ['nullable', 'exists:users,id'],
        ]);
    }
}
