<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Fiscal\Models\ProductFiscalData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ProductLogisticsController extends Controller
{
    public function update(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            // Obrigatórios: sem esses 4 a cotação de frete (Melhor Envio)
            // ignora o produto por completo — ver FreightQuoteService.
            // Envelope é a exceção: a pré-postagem dos Correios só pede
            // peso pra envelope, então as medidas ficam opcionais nele.
            'formato_embalagem' => ['required', 'in:'.ProductFiscalData::EMBALAGEM_CAIXA.','.ProductFiscalData::EMBALAGEM_ENVELOPE],
            'peso_bruto' => ['required', 'numeric', 'min:0.001'],
            'altura_cm' => ['required_unless:formato_embalagem,envelope', 'nullable', 'numeric', 'min:0.01'],
            'largura_cm' => ['required_unless:formato_embalagem,envelope', 'nullable', 'numeric', 'min:0.01'],
            'profundidade_cm' => ['required_unless:formato_embalagem,envelope', 'nullable', 'numeric', 'min:0.01'],
            'peso_liquido' => ['nullable', 'numeric', 'min:0'],
        ]);

        $product->fiscalData()->updateOrCreate(['product_id' => $product->id], $validated);

        return back()->with('success', 'Dados de logística atualizados com sucesso.');
    }
}
