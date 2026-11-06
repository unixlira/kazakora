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
     * O peso bruto (kg, dados fiscais) preenche o peso usado no custo de
     * envio do Mercado Livre, que depende do peso e do preço (06/11/2026).
     */
    public function index(): Response
    {
        return Inertia::render('Admin/PricingCalculator/Index', [
            'products' => Product::query()
                ->leftJoin('product_fiscal_data', 'product_fiscal_data.product_id', '=', 'products.id')
                ->orderBy('products.name')
                ->get([
                    'products.id',
                    'products.name',
                    'products.sku',
                    'products.price',
                    'products.cost_price',
                    'product_fiscal_data.peso_bruto as peso_kg',
                ]),
        ]);
    }
}
