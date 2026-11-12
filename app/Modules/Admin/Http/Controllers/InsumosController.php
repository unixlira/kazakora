<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Financeiro\Models\InsumoLancamento;
use App\Modules\Marketplace\Support\ContributionMargin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tela Insumos, menu Financeiro (pedido do Lira em 12/11/2026: "cria uma tela
 * de insumos que lá vou colocar valor de custo de materiais do escritório e
 * do galpão para entrar no custo"). Só admin. Lista por mês com total por
 * categoria; cada lançamento entra no lucro pela data (ContributionMargin).
 */
class InsumosController extends Controller
{
    public function index(Request $request): Response
    {
        $mes = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('mes')) ? (string) $request->query('mes') : now()->format('Y-m');
        $inicio = Carbon::createFromFormat('Y-m-d', $mes.'-01')->startOfDay();
        $fim = $inicio->copy()->addMonthNoOverflow();

        $lancamentos = InsumoLancamento::query()
            ->with('user:id,name')
            ->where('data', '>=', $inicio->toDateString())
            ->where('data', '<', $fim->toDateString())
            ->orderByDesc('data')->orderByDesc('id')
            ->get();

        $porCategoria = [];
        foreach (InsumoLancamento::CATEGORIAS as $chave => $rotulo) {
            $porCategoria[] = ['categoria' => $chave, 'rotulo' => $rotulo, 'total' => round((float) $lancamentos->where('categoria', $chave)->sum('valor'), 2)];
        }

        $embalados = array_sum(ContributionMargin::pedidosEmbalados($inicio, $fim));

        return Inertia::render('Admin/Financeiro/Insumos', [
            'mes' => $mes,
            'lancamentos' => $lancamentos->map(fn (InsumoLancamento $l) => [
                'id' => $l->id,
                'data' => $l->data->toDateString(),
                'descricao' => $l->descricao,
                'categoria' => $l->categoria,
                'valor' => (float) $l->valor,
                'observacao' => $l->observacao,
                'autor' => $l->user?->name,
            ])->values(),
            'categorias' => InsumoLancamento::CATEGORIAS,
            'porCategoria' => $porCategoria,
            'total' => round((float) $lancamentos->sum('valor'), 2),
            // A outra parte dos insumos: R$ fixo por pedido embalado (config).
            'embalagemPorPedido' => [
                'valor' => ContributionMargin::insumosPorPedido(),
                'pedidos' => $embalados,
                'total' => round($embalados * ContributionMargin::insumosPorPedido(), 2),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        InsumoLancamento::create($this->validar($request) + ['user_id' => $request->user()->id]);

        return back()->with('success', 'Insumo lançado.');
    }

    public function update(Request $request, InsumoLancamento $insumo): RedirectResponse
    {
        $insumo->update($this->validar($request));

        return back()->with('success', 'Insumo atualizado.');
    }

    public function destroy(InsumoLancamento $insumo): RedirectResponse
    {
        $insumo->delete();

        return back()->with('success', 'Insumo excluído.');
    }

    /** @return array<string, mixed> */
    private function validar(Request $request): array
    {
        return $request->validate([
            'data' => ['required', 'date'],
            'descricao' => ['required', 'string', 'max:200'],
            'categoria' => ['required', Rule::in(array_keys(InsumoLancamento::CATEGORIAS))],
            'valor' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'observacao' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
