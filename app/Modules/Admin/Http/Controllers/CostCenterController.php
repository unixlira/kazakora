<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cadastros\Models\CostCenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CostCenterController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/CostCenters/Index', [
            'costCenters' => CostCenter::query()->latest()->get(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/CostCenters/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        CostCenter::create($this->validated($request));

        return redirect()->route('admin.centros-de-custo.listar')->with('success', 'Centro de custo criado com sucesso.');
    }

    public function edit(CostCenter $costCenter): Response
    {
        return Inertia::render('Admin/CostCenters/Edit', ['costCenter' => $costCenter]);
    }

    public function update(Request $request, CostCenter $costCenter): RedirectResponse
    {
        $costCenter->update($this->validated($request));

        return redirect()->route('admin.centros-de-custo.listar')->with('success', 'Centro de custo atualizado com sucesso.');
    }

    public function destroy(CostCenter $costCenter): RedirectResponse
    {
        $costCenter->delete();

        return back()->with('success', 'Centro de custo removido com sucesso.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:20', 'unique:cost_centers,code,'.$request->route('cost_center')?->id],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);
    }
}
