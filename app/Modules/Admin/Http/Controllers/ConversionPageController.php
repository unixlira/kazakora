<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Support\MetaConversionPageResearchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class ConversionPageController extends Controller
{
    public function index(MetaConversionPageResearchService $research): Response
    {
        $snapshot = Cache::remember('admin.conversion-pages.meta-snapshot.v1', now()->addMinutes(90), fn (): array => $research->snapshot());

        return Inertia::render('Admin/ConversionPages/Index', [
            'summary' => [
                'totalCreatives' => count($snapshot['items']),
                'priorityCreatives' => collect($snapshot['items'])->where('conversionProxyScore', '>=', 80)->count(),
                'withLandingPage' => collect($snapshot['items'])->filter(fn (array $item): bool => filled($item['landingPageUrl'] ?? null))->count(),
                'averageActiveDays' => (int) round(collect($snapshot['items'])->avg('activeDays') ?? 0),
                'searchedAt' => $snapshot['searchedAt'],
            ],
            'creativeInsights' => $snapshot['items'],
            'keywords' => $snapshot['keywords'],
            'providerStatus' => $snapshot['providerStatus'],
            'sourceNotice' => 'Esta tela lista criativos reais encontrados na Meta Ads Library para o mercado de achados, Shopee, organização e utilidades. Não é lista de criativos internos; é benchmark para modelar peças similares sem copiar texto/imagem.',
            'scoreFormula' => 'Score proxy = longevidade ativa + repetição do criativo + link de destino + aderência ao catálogo + força do gancho. A Meta não expõe conversão real, ROAS ou vendas públicas.',
            'developerNote' => 'Antes de mexer neste fluxo em produção: git pull --ff-only. Se precisar conversão real, integrar Pixel/UTM/API própria; Meta Ads Library pública só permite inferência por sinais.',
        ]);
    }

    public function refresh(): RedirectResponse
    {
        Cache::forget('admin.conversion-pages.meta-snapshot.v1');
        return redirect('/admin/paginas-de-conversao')->with('success', 'Pesquisa da Meta Ads Library atualizada. Conversões reais não são públicas; o ranking usa proxy de longevidade e sinais de oferta.');
    }
}
