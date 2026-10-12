<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Support\Oportunidades\MaisVendidosMercadoLivre;
use App\Modules\Marketplace\Support\Oportunidades\MaisVendidosShopee;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Menu Oportunidades (correção do Lira 2026-10-12): produtos de TERCEIROS
 * que estão vendendo muito ou em alta no Mercado Livre e na Shopee, para
 * achar produto novo. Não mostra as vendas da KazaKora (a curva ABC da loja
 * continua no painel inicial). Uma tela por canal.
 */
class OportunidadesController extends Controller
{
    public const CANAIS = [
        'mercado-livre' => ['nome' => 'Mercado Livre'],
        'shopee' => ['nome' => 'Shopee'],
    ];

    /** Categoria inicial do Mercado Livre: Casa, Móveis e Decoração. */
    public const CATEGORIA_PADRAO = 'MLB1574';

    public function show(Request $request, string $canal, MaisVendidosMercadoLivre $ml, MaisVendidosShopee $shopee): Response
    {
        abort_unless(isset(self::CANAIS[$canal]), 404);

        $atualizar = $request->boolean('atualizar');

        if ($canal === 'mercado-livre') {
            $categoria = (string) $request->query('categoria', self::CATEGORIA_PADRAO);
            if (! preg_match('/^MLB\d{1,10}$/', $categoria)) {
                $categoria = self::CATEGORIA_PADRAO;
            }

            return Inertia::render('Admin/Oportunidades/Mercado', [
                'canal' => ['slug' => $canal, 'nome' => self::CANAIS[$canal]['nome']],
                'filtros' => ['categoria' => $categoria],
                'categorias' => $ml->categorias(),
                'mercado' => $ml->analisar($categoria, $atualizar) + ['configurado' => true],
            ]);
        }

        $termo = mb_substr(trim((string) $request->query('termo', '')), 0, 80);

        return Inertia::render('Admin/Oportunidades/Mercado', [
            'canal' => ['slug' => $canal, 'nome' => self::CANAIS[$canal]['nome']],
            'filtros' => ['termo' => $termo],
            'categorias' => [],
            'mercado' => $shopee->analisar($termo, $atualizar) + ['tendencias' => [], 'ocultos' => 0],
        ]);
    }
}
