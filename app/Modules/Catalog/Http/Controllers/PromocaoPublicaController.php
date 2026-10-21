<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Promocao;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Página pública da promoção (pedido do Lira 2026-10-21): título e descrição
 * definidos em Vender › Promoções. Fora do ar (inativa ou fora do período) dá
 * 404 para o cliente; a equipe logada vê a prévia com um aviso.
 *
 * Lê a promoção do cache (Promocao::doSlug), limpo quando ela é salva; o
 * "no ar" é conferido a cada visita, então o período vale na hora certa.
 */
class PromocaoPublicaController extends Controller
{
    /** Imagem do prêmio por promoção (a mesma do quadro na página do produto). */
    private const IMAGENS = ['airfryer' => 'images/promocoes/airfryer.jpg'];

    public function show(Request $request, string $slug): Response
    {
        $promocao = Promocao::doSlug($slug);
        abort_if($promocao === null, 404);
        $previa = ! $promocao->noAr();
        abort_if($previa && ! $request->user()?->isStaff(), 404);

        return Inertia::render('Promocoes/Ver', [
            'promocao' => [
                'titulo' => $promocao->titulo ?: $promocao->nome,
                'paragrafos' => $promocao->paragrafos(),
                'regras' => $promocao->regrasParaCliente(),
                'imagem_url' => isset(self::IMAGENS[$slug]) ? asset(self::IMAGENS[$slug]) : null,
            ],
            'previa' => $previa,
        ]);
    }
}
