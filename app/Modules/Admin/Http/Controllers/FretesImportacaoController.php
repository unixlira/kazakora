<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Support\ImportarRegrasDeFrete;
use App\Support\LerPlanilha;
use App\Support\SiteAtual;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logística → Fretes por estado → Importar planilha (pedido do Lira em
 * 2026-10-25): regras de frete de várias cidades e distritos de uma vez. A
 * tela manda o arquivo duas vezes: primeiro para a prévia (nada gravado) e,
 * depois do OK na tela, para gravar. Regras ImportarRegrasDeFrete.
 */
class FretesImportacaoController extends Controller
{
    public function previa(Request $request, ImportarRegrasDeFrete $importar): JsonResponse
    {
        $linhas = $this->linhas($request);

        return $linhas instanceof JsonResponse ? $linhas : response()->json($importar->analisar($linhas, SiteAtual::KAZAKORA));
    }

    public function aplicar(Request $request, ImportarRegrasDeFrete $importar): JsonResponse
    {
        $linhas = $this->linhas($request);
        if ($linhas instanceof JsonResponse) {
            return $linhas;
        }

        $resultado = $importar->aplicar($linhas, SiteAtual::KAZAKORA);
        Log::info('Fretes por estado: planilha importada', [
            'usuario' => $request->user()?->id,
            'arquivo' => $request->file('arquivo')->getClientOriginalName(),
            'gravadas' => $resultado['gravadas'],
            'resumo' => $resultado['resumo'],
        ]);

        return response()->json($resultado);
    }

    public function modelo(): Response
    {
        return response(ImportarRegrasDeFrete::modeloCsv(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="modelo-frete-por-cidade.csv"',
        ]);
    }

    /** Linhas da planilha enviada, ou a resposta 422 para a tela. */
    private function linhas(Request $request): array|JsonResponse
    {
        // Validator manual: fora de api/* o app redireciona no erro de validação.
        $validador = Validator::make($request->all(), [
            'arquivo' => ['required', 'file', 'max:5120'],
        ], [
            'arquivo.required' => 'Escolha a planilha.',
            'arquivo.max' => 'A planilha pode ter até 5 MB.',
        ]);
        if ($validador->fails()) {
            return response()->json(['message' => 'Planilha inválida.', 'errors' => $validador->errors()], 422);
        }

        $arquivo = $request->file('arquivo');
        try {
            return LerPlanilha::linhas($arquivo->getRealPath(), $arquivo->getClientOriginalName());
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Planilha inválida.', 'errors' => ['arquivo' => [
                $e instanceof \RuntimeException ? $e->getMessage() : 'Não consegui ler a planilha. Salve em .xlsx ou .csv e tente de novo.',
            ]]], 422);
        }
    }
}
