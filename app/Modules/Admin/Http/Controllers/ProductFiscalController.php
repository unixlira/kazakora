<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProductFiscalController extends Controller
{
    public function update(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'ncm' => ['nullable', 'string', 'max:8'],
            'cest' => ['nullable', 'string', 'max:7'],
            'cfop' => ['nullable', 'string', 'max:4'],
            'origem' => ['required', 'integer', 'between:0,8'],
            'gtin' => ['nullable', 'string', 'max:14'],
            'unidade_tributavel' => ['required', 'string', 'max:6'],
            'icms_situacao_tributaria' => ['nullable', 'string', 'max:3'],
            'icms_aliquota' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'ipi_situacao_tributaria' => ['nullable', 'string', 'max:3'],
            'ipi_aliquota' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'pis_situacao_tributaria' => ['nullable', 'string', 'max:3'],
            'pis_aliquota' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cofins_situacao_tributaria' => ['nullable', 'string', 'max:3'],
            'cofins_aliquota' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'peso_bruto' => ['nullable', 'numeric', 'min:0'],
            'peso_liquido' => ['nullable', 'numeric', 'min:0'],
            'altura_cm' => ['nullable', 'numeric', 'min:0'],
            'largura_cm' => ['nullable', 'numeric', 'min:0'],
            'profundidade_cm' => ['nullable', 'numeric', 'min:0'],
        ]);

        $product->fiscalData()->updateOrCreate(['product_id' => $product->id], $validated);

        return back()->with('success', 'Dados fiscais atualizados com sucesso.');
    }
}
