<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Cadastros\Models\CostCenter;
use App\Modules\Financeiro\Models\CashFlowEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CashFlowController extends Controller
{
    public function index(): Response
    {
        $entries = CashFlowEntry::query()->with('costCenter:id,name', 'creator:id,name')->latest('entry_date')->get();

        $income = (float) $entries->where('type', CashFlowEntry::TYPE_INCOME)->sum('amount');
        $expense = (float) $entries->where('type', CashFlowEntry::TYPE_EXPENSE)->sum('amount');

        return Inertia::render('Admin/CashFlow/Index', [
            'entries' => $entries,
            'costCenters' => CostCenter::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'summary' => [
                'balance' => $income - $expense,
                'income' => $income,
                'expense' => $expense,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);
        $validated['created_by'] = $request->user()->id;

        CashFlowEntry::create($validated);

        return back()->with('success', 'Lançamento adicionado ao fluxo de caixa.');
    }

    public function update(Request $request, CashFlowEntry $cashFlowEntry): RedirectResponse
    {
        $cashFlowEntry->update($this->validated($request));

        return back()->with('success', 'Lançamento atualizado.');
    }

    public function destroy(CashFlowEntry $cashFlowEntry): RedirectResponse
    {
        $cashFlowEntry->delete();

        return back()->with('success', 'Lançamento removido.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in([CashFlowEntry::TYPE_INCOME, CashFlowEntry::TYPE_EXPENSE])],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'cost_center_id' => ['nullable', 'exists:cost_centers,id'],
            'entry_date' => ['required', 'date'],
        ]);
    }
}
