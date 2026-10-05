<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Support\DigitalMarketingResearchService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class DigitalMarketingController extends Controller
{
    public function index(DigitalMarketingResearchService $research): Response
    {
        return Inertia::render('Admin/DigitalMarketing/Index', $research->dashboardSnapshot());
    }

    public function refresh(DigitalMarketingResearchService $research): RedirectResponse
    {
        $research->refreshSnapshot();

        return redirect('/admin/mkt-digital')->with('success', 'Radar de MKT Digital atualizado. Use os criativos como referência de estrutura, não como cópia.');
    }
}
