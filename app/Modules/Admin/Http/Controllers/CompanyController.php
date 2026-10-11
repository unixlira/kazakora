<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesShopeeAuthorizationLanding;
use App\Http\Controllers\Controller;
use App\Modules\Fiscal\Models\Company;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    use HandlesShopeeAuthorizationLanding;

    private const REGIMES = [
        Company::REGIME_MEI,
        Company::REGIME_SIMPLES_NACIONAL,
        Company::REGIME_LUCRO_PRESUMIDO,
        Company::REGIME_LUCRO_REAL,
    ];

    /**
     * Um dos 3 destinos plausíveis do redirect de autorização "Seller In
     * House" da Shopee — ver HandlesShopeeAuthorizationLanding.
     */
    public function edit(Request $request): Response|RedirectResponse
    {
        if ($redirect = $this->shopeeAuthorizationLandingRedirect($request)) {
            return $redirect;
        }

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
            'cnpj' => [
                'required', 'string', 'max:18',
                function ($attribute, $value, $fail): void {
                    if (strlen(preg_replace('/\D/', '', $value)) !== 14) {
                        $fail('CNPJ inválido — deve ter 14 dígitos.');
                    }
                },
            ],
            'inscricao_estadual' => ['nullable', 'string', 'max:20', 'regex:/^(ISENTO|[0-9.\-\/]+)$/i'],
            'inscricao_municipal' => ['nullable', 'string', 'max:20'],
            'regime_tributario' => ['required', 'in:'.implode(',', self::REGIMES)],
            'cnae' => ['nullable', 'string', 'max:20'],
            'phone' => [
                'nullable', 'string', 'max:20',
                function ($attribute, $value, $fail): void {
                    $digits = strlen(preg_replace('/\D/', '', $value ?? ''));
                    if ($digits !== 0 && ! in_array($digits, [10, 11], true)) {
                        $fail('Telefone inválido — use DDD + número.');
                    }
                },
            ],
            'email' => ['nullable', 'email', 'max:255'],
            'zip' => ['nullable', 'string', 'max:9'],
            'street' => ['nullable', 'string', 'max:255'],
            'number' => ['nullable', 'string', 'max:20'],
            'complement' => ['nullable', 'string', 'max:255'],
            'neighborhood' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:2'],
            'certificado' => [
                'nullable', 'file', 'max:5120',
                function ($attribute, $value, $fail): void {
                    if (! in_array(strtolower($value->getClientOriginalExtension()), ['pfx', 'p12'], true)) {
                        $fail('O certificado deve ser um arquivo .pfx ou .p12.');
                    }
                },
            ],
            'certificado_senha' => ['nullable', 'string', 'max:255', 'required_with:certificado'],
        ]);

        $company = Company::query()->first() ?? new Company();

        if ($request->hasFile('certificado')) {
            if ($company->certificate_path) {
                Storage::disk('local')->delete($company->certificate_path);
            }

            $validated['certificate_path'] = $request->file('certificado')->store('nfe', 'local');
        }

        if (filled($validated['certificado_senha'] ?? null)) {
            $validated['certificate_password'] = $validated['certificado_senha'];
        }

        unset($validated['certificado'], $validated['certificado_senha']);

        if ($company->exists) {
            $company->update($validated);
        } else {
            $company->fill($validated)->save();
        }

        return back()->with('success', 'Dados da empresa atualizados com sucesso.');
    }
}
