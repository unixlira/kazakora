<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductQuantityDiscountController extends Controller
{
    public function update(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'discounts' => ['array'],
            'discounts.*.min_quantity' => ['required', 'integer', 'min:2'],
            'discounts.*.discount_percentage' => ['required', 'numeric', 'min:0.01', 'max:100'],
        ]);

        $rows = collect($validated['discounts'] ?? []);

        if ($rows->pluck('min_quantity')->unique()->count() !== $rows->count()) {
            return back()->withErrors(['discounts' => 'Cada faixa de quantidade só pode aparecer uma vez.']);
        }

        DB::transaction(function () use ($product, $rows) {
            $product->quantityDiscounts()->delete();

            foreach ($rows as $row) {
                $product->quantityDiscounts()->create([
                    'min_quantity' => $row['min_quantity'],
                    'discount_percentage' => $row['discount_percentage'],
                ]);
            }
        });

        return back()->with('success', 'Descontos por quantidade atualizados com sucesso.');
    }
}
