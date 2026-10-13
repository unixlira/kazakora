<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Support\Oportunidades\InteligenciaMercadoLivre;
use App\Modules\Marketplace\Support\Oportunidades\MaisVendidosMercadoLivre;
use App\Modules\Marketplace\Support\Oportunidades\MaisVendidosShopee;
use App\Modules\Marketplace\Support\Oportunidades\RadarShopee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Menu Oportunidades (correção do Lira 2026-10-12): produtos de TERCEIROS
 * que estão vendendo muito ou em alta no Mercado Livre e na Shopee, para
 * achar produto novo. Não mostra as vendas da KazaKora (a curva ABC da loja
 * continua no painel inicial). Uma tela por canal.
 *
 * Loader (pedido do Lira 2026-10-12): a coleta do Mercado Livre faz ~40
 * chamadas à API sem cache e travava a navegação sem nenhum aviso. Os dados
 * de mercado agora são prop adiada (Inertia::defer): a tela abre na hora com
 * o esqueleto de carregamento e os números chegam numa segunda requisição.
 * A conta e os números não mudaram.
 *
 * Tela dividida (pedido do Lira 2026-10-12): no Mercado Livre, em cima os
 * mais vendidos da categoria e embaixo o bloco do Radar (prop adiada
 * `mercadoLivre`, grupo próprio). Só abre por Oportunidades › Mercado
 * Livre: /admin/radar/mercado-livre redireciona para cá (Lira 2026-10-12).
 *
 * Radar da Shopee (pedido do Lira 2026-10-12): na Shopee, o ranking
 * "Produtos em alta" da Central do Vendedor, guardado no banco (RadarShopee),
 * vem em cima, como prop adiada própria (`radarShopee`).
 *
 * Inteligência (pedido do Lira 2026-10-13): botão que passa os mais vendidos
 * da categoria pelo filtro "demanda, concorrência e margem" e abre num modal
 * (InteligenciaMercadoLivre). Usa o mesmo cache de 6 h, sem forçar consulta.
 */
class OportunidadesController extends Controller
{
    public const CANAIS = [
        'mercado-livre' => ['nome' => 'Mercado Livre'],
        'shopee' => ['nome' => 'Shopee'],
    ];

    /** Categoria inicial do Mercado Livre: Casa, Móveis e Decoração. */
    public const CATEGORIA_PADRAO = 'MLB1574';

    public function inteligencia(Request $request, MaisVendidosMercadoLivre $ml, InteligenciaMercadoLivre $inteligencia): JsonResponse
    {
        $categoria = self::categoria($request);

        return response()->json(['categoria' => $categoria] + $inteligencia->classificar($ml->analisar($categoria)));
    }

    /** Categoria do ML da query; inválida volta para a padrão (mesma regra da tela). */
    private static function categoria(Request $request): string
    {
        $categoria = (string) $request->query('categoria', self::CATEGORIA_PADRAO);

        return preg_match('/^MLB\d{1,10}$/', $categoria) ? $categoria : self::CATEGORIA_PADRAO;
    }

    public function show(Request $request, string $canal, MaisVendidosMercadoLivre $ml, MaisVendidosShopee $shopee, RadarShopee $radarShopee): Response
    {
        abort_unless(isset(self::CANAIS[$canal]), 404);

        $atualizar = $request->boolean('atualizar');

        if ($canal === 'mercado-livre') {
            $categoria = self::categoria($request);
            // Filtro de concorrência (Lira 2026-10-13): vale para as duas
            // listas da tela; a coleta e o cache continuam os mesmos.
            $concorrencia = in_array($request->query('concorrencia'), MaisVendidosMercadoLivre::NIVEIS_CONCORRENCIA, true)
                ? (string) $request->query('concorrencia') : null;
            $filtrar = fn (array $dados) => ['produtos' => MaisVendidosMercadoLivre::filtrarConcorrencia($dados['produtos'] ?? [], $concorrencia)] + $dados;

            return Inertia::render('Admin/Oportunidades/Mercado', [
                'canal' => ['slug' => $canal, 'nome' => self::CANAIS[$canal]['nome']],
                'base' => '/'.$request->path(),
                'filtros' => ['categoria' => $categoria, 'concorrencia' => $concorrencia],
                'categoriaPadrao' => self::CATEGORIA_PADRAO,
                'categorias' => $ml->categorias(),
                'mercado' => Inertia::defer(fn () => $filtrar($ml->analisar($categoria, $atualizar)) + ['configurado' => true]),
                'mercadoLivre' => Inertia::defer(fn () => $filtrar(RadarController::mercadoLivre($ml, $atualizar)), 'radar'),
            ]);
        }

        $termo = mb_substr(trim((string) $request->query('termo', '')), 0, 80);

        return Inertia::render('Admin/Oportunidades/Mercado', [
            'canal' => ['slug' => $canal, 'nome' => self::CANAIS[$canal]['nome']],
            'base' => '/'.$request->path(),
            'filtros' => ['termo' => $termo],
            'categorias' => [],
            'mercado' => Inertia::defer(fn () => $shopee->analisar($termo, $atualizar) + ['tendencias' => [], 'ocultos' => 0]),
            // Filtros do Radar da Shopee vão ao servidor (paginação no banco).
            'radarShopee' => Inertia::defer(fn () => $radarShopee->analisar(array_filter([
                'l1' => $request->query('rs_l1'),
                'categoria' => $request->query('rs_cat'),
                'busca' => $request->query('rs_busca'),
                'pagina' => $request->query('rs_pagina'),
            ], fn ($v) => $v !== null)), 'radar'),
        ]);
    }
}
