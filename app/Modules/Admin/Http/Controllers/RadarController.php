<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tela /admin/radar (2026-10-12): estrutura pronta, conteúdo em construção.
 * O nome visível fica em resources/js/Shared/radarNome.js.
 */
class RadarController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Radar/Index');
    }
}
