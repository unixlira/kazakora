<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Support\Diagnostico\AcoesShopee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Botão de ação do diagnóstico do Cauê no dashboard (pedido 2026-10-14):
 * aplica na Shopee a correção sugerida e marca no diagnóstico que foi feita.
 */
class DiagnosticoAcaoController extends Controller
{
    public function shopee(Request $request, AcoesShopee $acoes): RedirectResponse
    {
        $dados = $request->validate([
            'item_id' => ['required', 'regex:/^\d+$/'],
            'acao' => ['required', 'in:'.implode(',', AcoesShopee::ACOES)],
            'valor' => ['nullable', 'numeric'],
            'model_id' => ['nullable', 'regex:/^\d+$/'],
        ]);

        try {
            $r = $acoes->executar($dados['acao'], $dados['item_id'], isset($dados['valor']) ? (float) $dados['valor'] : null, $dados['model_id'] ?? null, $request->user()?->id);
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Não foi possível aplicar: '.$e->getMessage());
        }

        if ($r['ok']) {
            $this->marcarFeita($dados['item_id'], $dados['acao'], $dados['valor'] ?? null);
        }

        return back()->with($r['ok'] ? 'success' : 'warning', $r['mensagem']);
    }

    /** Variações do anúncio, para o botão de preço escolher qual mudar. */
    public function modelos(string $itemId, AcoesShopee $acoes)
    {
        abort_unless(ctype_digit($itemId), 404);
        try {
            return response()->json(['modelos' => array_values($acoes->modelos($itemId))]);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['erro' => $e->getMessage()], 502);
        }
    }

    private function marcarFeita(string $itemId, string $acao, $valor): void
    {
        $linha = DB::table('diagnosticos_canal')->where('canal', 'shopee')->first();
        if (! $linha) {
            return;
        }
        $diag = json_decode($linha->dados, true) ?: [];
        $diag['feitas'][$itemId][$acao] = ['em' => now()->toIso8601String(), 'valor' => $valor];
        DB::table('diagnosticos_canal')->where('canal', 'shopee')->update(['dados' => json_encode($diag, JSON_UNESCAPED_UNICODE)]);
    }
}
