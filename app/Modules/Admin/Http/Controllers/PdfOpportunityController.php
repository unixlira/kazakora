<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Support\PdfOpportunityResearchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class PdfOpportunityController extends Controller
{
    public function index(PdfOpportunityResearchService $research): Response
    {
        $snapshot = Cache::remember('admin.pdf-opportunities.snapshot.v1', now()->addMinutes(90), fn (): array => $research->snapshot());

        return Inertia::render('Admin/PdfOpportunities/Index', $snapshot);
    }

    public function refresh(): RedirectResponse
    {
        Cache::forget('admin.pdf-opportunities.snapshot.v1');

        return redirect('/admin/pdf-oportunidades')->with('success', 'Pesquisa de oportunidades de PDF atualizada. O ranking usa sinais públicos e proxy comercial.');
    }
}
