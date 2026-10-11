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
                // The classic search endpoint (sites/{site}/search) currently
                // returns 403 "forbidden" for this app even with a valid,
                // connected seller token — Mercado Livre restricts it behind
                // partner approval now. listing_prices/categories work fine
                // with the same token, so this is specifically a search
                // access issue, not a connection/auth problem.
                $error = $e->getCode() === 403
                    ? ['type' => 'search_restricted', 'message' => 'O Mercado Livre bloqueou a busca de anúncios para este aplicativo (comum para apps sem aprovação de parceiro para esse recurso). Entre em contato com o suporte do Mercado Livre para solicitar acesso à busca — o restante da integração (conexão da conta, cálculo de comissão) já funciona normalmente.']
                    : ['type' => 'generic', 'message' => $e->getMessage()];
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
