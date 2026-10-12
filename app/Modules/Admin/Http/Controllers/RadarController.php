<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Support\Oportunidades\MaisVendidosMercadoLivre;
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
 * junta numa tela o que está vendendo e em alta em três fontes:
 * TikTok Shop (Kalodata), Mercado Livre (mais vendidos de Casa e buscas em
 * alta do site) e Google Trends. Cada fonte é
 * prop adiada num grupo próprio: a tela abre na hora e cada bloco chega
 * quando a sua consulta termina, sem uma fonte lenta segurar as outras.
 * O cruzamento ("em alta em mais de um lugar") é feito na tela.
 *
 * Layout v4 aprovado pelo Lira 2026-10-12: a Shopee sai da tela enquanto a
 * API de Afiliados não for liberada. MaisVendidosShopee continua no código
 * (usado em Oportunidades › Shopee); para religar, injetar aqui e repor a
 * prop 'shopee' e o bloco na tela.
 *
 * Submenu do Radar (pedido do Lira 2026-10-12): /admin/radar/{fonte} abre a
 * mesma tela só com o bloco daquela fonte, e só a consulta dela roda.
 *
 * Menu de 2026-10-12 (pedido do Lira): TikTok e Google viraram itens de
 * Oportunidades e o Mercado Livre virou Oportunidades › Mercado Livre ›
 * Radar, que abre a tela do OportunidadesController (mais vendidos em cima,
 * este bloco do ML embaixo). Por isso 'mercado-livre' saiu de FONTES; o
 * bloco continua no Radar geral (/admin/radar).
 */
class RadarController extends Controller
{
    /** Fonte da URL => nome visível (o mesmo do submenu). */
    public const FONTES = [
        'tiktok' => 'TikTok',
        'google' => 'Google',
    ];

    public function index(
        Request $request,
        MaisVendidosTikTok $tiktok,
        MaisVendidosMercadoLivre $ml,
        TendenciasGoogle $google,
        ?string $fonte = null,
    ): Response {
        // "Consultar de novo" refaz ML e Google; o TikTok fica no
        // cache de 24 h porque o Kalodata gasta crédito.
        $atualizar = $request->boolean('atualizar');

        // Chave = fonte da URL; a prop adiada só roda se a fonte ficar na tela.
        $blocos = [
            'tiktok' => ['tiktok', Inertia::defer(fn () => $tiktok->analisar(), 'tiktok')],
            'mercado-livre' => ['mercadoLivre', Inertia::defer(fn () => self::mercadoLivre($ml, $atualizar), 'mercado-livre')],
            'google' => ['google', Inertia::defer(fn () => $google->analisar($atualizar), 'google')],
        ];
        if ($fonte !== null) {
            $blocos = [$fonte => $blocos[$fonte]];
        }

        return Inertia::render('Admin/Radar/Index', [
            'fonte' => $fonte,
            'fonteNome' => $fonte === null ? null : self::FONTES[$fonte],
            ...array_column($blocos, 1, 0),
        ]);
    }

    /** Bloco do Mercado Livre: mais vendidos de Casa e as buscas em alta do site. */
    public static function mercadoLivre(MaisVendidosMercadoLivre $ml, bool $atualizar = false): array
    {
        return $ml->analisar(OportunidadesController::CATEGORIA_PADRAO, $atualizar)
            + ['buscas' => $ml->tendenciasGerais($atualizar)['tendencias']];
    }
}
