<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use Inertia\Inertia;
use Inertia\Response;

class PricingCalculatorController extends Controller
{
    /**
     * Calculadora de precificação por marketplace — tela sem persistência
     * própria (as taxas por canal vivem em `resources/js/Shared/marketplaceFees.js`,
     * já que mudam com frequência e a fonte de verdade é a documentação de
     * cada marketplace, não o banco). Só entrega a lista de produtos reais
     * para o preenchimento rápido do custo a partir de um produto existente.
     */
    public function index(): Response
    {
        return Inertia::render('Admin/PricingCalculator/Index', [
            'products' => Product::query()
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'price', 'cost_price']),
        ]);
    }
}
