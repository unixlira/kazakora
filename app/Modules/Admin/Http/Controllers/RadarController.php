<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Support\Oportunidades\MaisVendidosMercadoLivre;
use App\Modules\Marketplace\Support\Oportunidades\MaisVendidosShopee;
use App\Modules\Marketplace\Support\Oportunidades\MaisVendidosTikTok;
use App\Modules\Marketplace\Support\Oportunidades\TendenciasGoogle;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Radar de Oportunidades (2026-10-12): uma tela só, dentro do grupo
 * Oportunidades do menu, sem separar por marketplace. O nome visível fica
 * em resources/js/Shared/radarNome.js.
 *
 * Pedido do Lira 2026-10-12: o Kalodata cobre só o TikTok, então o Radar
 * junta numa tela o que está vendendo e em alta em quatro fontes:
 * TikTok Shop (Kalodata), Shopee (API de Afiliados), Mercado Livre (mais
 * vendidos de Casa e buscas em alta do site) e Google Trends. Cada fonte é
 * prop adiada num grupo próprio: a tela abre na hora e cada bloco chega
 * quando a sua consulta termina, sem uma fonte lenta segurar as outras.
 * O cruzamento ("em alta em mais de um lugar") é feito na tela.
 */
class RadarController extends Controller
{
    public function index(
        Request $request,
        MaisVendidosTikTok $tiktok,
        MaisVendidosShopee $shopee,
        MaisVendidosMercadoLivre $ml,
        TendenciasGoogle $google,
    ): Response {
        // "Consultar de novo" refaz ML, Shopee e Google; o TikTok fica no
        // cache de 24 h porque o Kalodata gasta crédito.
        $atualizar = $request->boolean('atualizar');

        return Inertia::render('Admin/Radar/Index', [
            'tiktok' => Inertia::defer(fn () => $tiktok->analisar(), 'tiktok'),
            'shopee' => Inertia::defer(fn () => $shopee->analisar('', $atualizar), 'shopee'),
            'mercadoLivre' => Inertia::defer(fn () => $ml->analisar(OportunidadesController::CATEGORIA_PADRAO, $atualizar)
                + ['buscas' => $ml->tendenciasGerais($atualizar)['tendencias']], 'mercado-livre'),
            'google' => Inertia::defer(fn () => $google->analisar($atualizar), 'google'),
        ]);
    }
}
