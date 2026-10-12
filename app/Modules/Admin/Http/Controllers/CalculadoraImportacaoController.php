<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Services\CotacaoDolarService;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Calculadora de importação (pedido do Lira em 2026-10-12, cópia das funções
 * de mentoria.destraveescale.com.br/calculadora-importacao): custo real de
 * uma remessa com imposto federal de 60%, ICMS "por dentro" e taxa
 * administrativa, custo por peça e simulação de lucro. Só calcula: a conta
 * fica na tela (resources/js/Shared/custoImportacao.js), sem gravar nada.
 */
class CalculadoraImportacaoController extends Controller
{
    public function index(CotacaoDolarService $cotacao): Response
    {
        return Inertia::render('Admin/CalculadoraImportacao/Index', [
            'cotacao' => $cotacao->atual(),
        ]);
    }

    /** Botão "Atualizar" da tela: busca de novo, sem o cache. */
    public function cotacao(CotacaoDolarService $cotacao): JsonResponse
    {
        $atual = $cotacao->atual(renovar: true);

        return $atual
            ? response()->json($atual)
            : response()->json(['message' => 'Não foi possível buscar a cotação agora. Digite o valor à mão.'], 503);
    }
}
