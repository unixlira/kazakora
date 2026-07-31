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
            // Obrigatórios: sem esses 4 a cotação de frete (Melhor Envio)
            // ignora o produto por completo — ver FreightQuoteService.
            'peso_bruto' => ['required', 'numeric', 'min:0.001'],
            'altura_cm' => ['required', 'numeric', 'min:0.01'],
            'largura_cm' => ['required', 'numeric', 'min:0.01'],
            'profundidade_cm' => ['required', 'numeric', 'min:0.01'],
            'peso_liquido' => ['nullable', 'numeric', 'min:0'],
        ]);

        $product->fiscalData()->updateOrCreate(['product_id' => $product->id], $validated);

        return back()->with('success', 'Dados de logística atualizados com sucesso.');
    }
}
