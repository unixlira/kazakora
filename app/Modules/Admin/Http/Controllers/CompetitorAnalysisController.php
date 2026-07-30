<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Services\MercadoLivre\Exceptions\MercadoLivreException;
use App\Services\MercadoLivre\Exceptions\RateLimitException;
use App\Services\MercadoLivre\Services\CompetitorAnalysisService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CompetitorAnalysisController extends Controller
{
    public function __construct(private readonly CompetitorAnalysisService $competitorAnalysis) {}

    public function index(Request $request): Response
    {
        $selectedProduct = null;
        $results = [];
        $error = null;

        if ($request->filled('product_id')) {
            $selectedProduct = Product::query()->with('category:id,name')->findOrFail($request->integer('product_id'));

            try {
                $payload = $this->competitorAnalysis->search($selectedProduct);
                $results = $payload['results'];
            } catch (RateLimitException $e) {
                $error = ['type' => 'rate_limit', 'message' => $e->getMessage()];
            } catch (MercadoLivreException $e) {
                $error = ['type' => 'generic', 'message' => $e->getMessage()];
            }
        }

        return Inertia::render('Admin/CompetitorAnalysis/Index', [
            'products' => Product::query()->orderBy('name')->get(['id', 'name', 'sku', 'price']),
            'selectedProduct' => $selectedProduct,
            'results' => $results,
            'error' => $error,
        ]);
    }
}
