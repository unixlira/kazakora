<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Fiscal\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    private const REGIMES = [
        Company::REGIME_MEI,
        Company::REGIME_SIMPLES_NACIONAL,
        Company::REGIME_LUCRO_PRESUMIDO,
        Company::REGIME_LUCRO_REAL,
    ];

    public function edit(): Response
    {
        return Inertia::render('Admin/Company/Edit', [
            'company' => Company::query()->first(),
            'regimes' => self::REGIMES,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'razao_social' => ['required', 'string', 'max:255'],
            'nome_fantasia' => ['nullable', 'string', 'max:255'],
            'cnpj' => ['required', 'string', 'max:18'],
            'inscricao_estadual' => ['nullable', 'string', 'max:20'],
            'inscricao_municipal' => ['nullable', 'string', 'max:20'],
            'regime_tributario' => ['required', 'in:'.implode(',', self::REGIMES)],
            'cnae' => ['nullable', 'string', 'max:20'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'zip' => ['nullable', 'string', 'max:9'],
            'street' => ['nullable', 'string', 'max:255'],
            'number' => ['nullable', 'string', 'max:20'],
            'complement' => ['nullable', 'string', 'max:255'],
            'neighborhood' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:2'],
        ]);

        $company = Company::query()->first();

        if ($company) {
            $company->update($validated);
        } else {
            Company::create($validated);
        }

        return back()->with('success', 'Dados da empresa atualizados com sucesso.');
    }
}
