<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProductLogisticsController extends Controller
{
    public function update(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'peso_bruto' => ['nullable', 'numeric', 'min:0'],
            'peso_liquido' => ['nullable', 'numeric', 'min:0'],
            'altura_cm' => ['nullable', 'numeric', 'min:0'],
            'largura_cm' => ['nullable', 'numeric', 'min:0'],
            'profundidade_cm' => ['nullable', 'numeric', 'min:0'],
        ]);

        $product->fiscalData()->updateOrCreate(['product_id' => $product->id], $validated);

        return back()->with('success', 'Dados de logística atualizados com sucesso.');
    }
}
