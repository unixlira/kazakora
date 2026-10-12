<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Radar de Oportunidades (2026-10-12): uma tela só, dentro do grupo
 * Oportunidades do menu, sem separar por marketplace. Conteúdo ainda em
 * construção. O nome visível fica em resources/js/Shared/radarNome.js.
 */
class RadarController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Radar/Index');
    }
}
