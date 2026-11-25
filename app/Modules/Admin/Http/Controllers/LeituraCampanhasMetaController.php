<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Support\Publicidade\CampanhasMetaPorLoja;
use App\Modules\Admin\Support\Publicidade\CpaDoProduto;
use App\Modules\Admin\Support\Publicidade\LeituraCampanhasMeta;
use App\Support\Rbac\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Publicidade › Meta Ads › Leitura das campanhas (pedido do Lira em
 * 25/11/2026): conjunto a conjunto, com semáforo e "O que fazer", para quem
 * está aprendendo a ler tráfego. Só LÊ a Meta; grava no banco apenas o
 * início do ciclo de cada campanha e o CPA digitado por produto.
 */
class LeituraCampanhasMetaController extends Controller
{
    public function index(Request $request, LeituraCampanhasMeta $leitura, CampanhasMetaPorLoja $porLoja): Response
    {
        $loja = $porLoja->umaLoja($request->query('loja'));
        $periodo = LeituraCampanhasMeta::periodo($request->query('periodo'));
        $status = LeituraCampanhasMeta::status($request->query('status'));
        $atualizar = $request->boolean('atualizar');

        return Inertia::render('Admin/MetaAds/Leitura', [
            'lojas' => $porLoja->lojas(),
            'loja' => $loja,
            'periodo' => $periodo,
            'periodos' => LeituraCampanhasMeta::PERIODOS,
            'status' => $status,
            'statusFiltros' => LeituraCampanhasMeta::STATUS,
            'faixas' => config('leitura_meta.faixas'),
            'metricaProfessor' => (bool) config('leitura_meta.metrica_professor'),
            'podeEditar' => Permissions::has($request->user(), 'financeiro.create'),
            'dados' => Inertia::defer(fn () => $leitura->montar($loja, $periodo, $status, $atualizar)),
        ]);
    }

    public function ciclo(Request $request, CampanhasMetaPorLoja $porLoja): RedirectResponse
    {
        $dados = $request->validate([
            'loja' => ['required', 'string', 'in:'.CampanhasMetaPorLoja::KAZAKORA.','.CampanhasMetaPorLoja::FERRAKORA],
            'campanha_id' => ['required', 'regex:/^\d{1,30}$/'],
            'inicio' => ['nullable', 'date'],
        ]);

        LeituraCampanhasMeta::salvarCiclo($porLoja->umaLoja($dados['loja']), $dados['campanha_id'], $dados['inicio'] ?? null);

        return back()->with('success', filled($dados['inicio'] ?? null) ? 'Início do ciclo salvo.' : 'Ciclo voltou para o início da campanha na Meta.');
    }

    public function cpa(Request $request): RedirectResponse
    {
        $dados = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'cpa_alvo' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'cpa_maximo' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ]);

        CpaDoProduto::sobrescrever((int) $dados['product_id'],
            isset($dados['cpa_alvo']) ? (float) $dados['cpa_alvo'] : null,
            isset($dados['cpa_maximo']) ? (float) $dados['cpa_maximo'] : null);

        return back()->with('success', 'CPA do produto salvo.');
    }
}
