<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Radar (2026-10-12): grupo próprio no menu, com uma tela por canal
 * (/admin/radar/shopee e /admin/radar/mercado-livre). Conteúdo ainda em
 * construção. O nome visível do grupo fica em resources/js/Shared/radarNome.js.
 */
class RadarController extends Controller
{
    /** Canais do Radar: slug da URL => dados que a tela mostra. */
    public const CANAIS = [
        'shopee' => [
            'nome' => 'Shopee',
            'icone' => 'fas fa-bag-shopping',
            'descricao' => 'Oportunidades da Shopee: ADS e meta de ROAS, preço, qualidade do anúncio e promoções.',
        ],
        'mercado-livre' => [
            'nome' => 'Mercado Livre',
            'icone' => 'fas fa-handshake',
            'descricao' => 'Oportunidades do Mercado Livre: Central de Promoções, Mercado Ads e preço competitivo.',
        ],
    ];

    public function index(): RedirectResponse
    {
        return redirect()->route('admin.radar.canal', 'shopee');
    }

    public function show(string $canal): Response
    {
        return Inertia::render('Admin/Radar/Index', [
            'canal' => ['slug' => $canal, ...self::CANAIS[$canal]],
        ]);
    }
}
