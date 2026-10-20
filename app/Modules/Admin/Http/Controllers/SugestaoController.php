<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Jobs\GerarSugestoesJob;
use App\Modules\Marketplace\Models\Sugestao;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Botões do board Sugestões do dia (Indicadores, 2026-10-20). Marcar como
 * feita ou ignorada só registra; não muda nada na loja nem no anúncio.
 */
class SugestaoController extends Controller
{
    public function status(Request $request, Sugestao $sugestao): RedirectResponse
    {
        $dados = $request->validate(['status' => ['required', Rule::in(Sugestao::STATUS)]]);
        $aberta = $dados['status'] === Sugestao::STATUS_ABERTA;

        $sugestao->update([
            'status' => $dados['status'],
            'resolvida_em' => $aberta ? null : now(),
            'resolvida_por' => $aberta ? null : $request->user()->id,
        ]);

        return back();
    }

    public function rodar(): RedirectResponse
    {
        GerarSugestoesJob::dispatch();

        return back()->with('success', 'Rotina das sugestões na fila. Leva alguns minutos; recarregue a página depois.');
    }
}
