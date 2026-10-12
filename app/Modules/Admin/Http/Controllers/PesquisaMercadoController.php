<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\PesquisaMercado;
use App\Modules\Admin\Support\CurvaAbcPorCanal;
use App\Modules\Catalog\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Menu Pesquisas (pedido do Lira 2026-10-12): registrar e consultar
 * pesquisas de mercado sobre produtos campeões, por plataforma. Separado
 * do Radar (ProductRadar), que só consulta dados gerados sozinhos.
 */
class PesquisaMercadoController extends Controller
{
    public function index(Request $request): Response
    {
        $aba = array_key_exists((string) $request->query('aba'), PesquisaMercado::ABAS)
            ? (string) $request->query('aba')
            : 'mercado_livre';
        $busca = trim((string) $request->query('busca', ''));
        $status = array_key_exists((string) $request->query('status'), PesquisaMercado::STATUS)
            ? (string) $request->query('status')
            : null;

        $pesquisas = PesquisaMercado::query()
            ->with('autor:id,name')
            ->withCount('itens')
            ->whereIn('plataforma', PesquisaMercado::ABAS[$aba])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($busca !== '', function ($q) use ($busca) {
                $termo = '%'.$busca.'%';
                $q->where(fn ($w) => $w
                    ->where('titulo', 'like', $termo)
                    ->orWhere('resumo', 'like', $termo)
                    ->orWhere('plataforma_nome', 'like', $termo)
                    ->orWhereHas('itens', fn ($i) => $i->where('produto', 'like', $termo)->orWhere('vendedor', 'like', $termo)));
            })
            ->orderByDesc('data_pesquisa')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/Pesquisas/Index', [
            'pesquisas' => $pesquisas,
            'filtros' => ['aba' => $aba, 'busca' => $busca, 'status' => $status],
            'contagem' => $this->contagemPorAba(),
            'opcoes' => $this->opcoes(),
        ]);
    }

    public function create(Request $request): Response
    {
        $plataforma = array_key_exists((string) $request->query('plataforma'), PesquisaMercado::PLATAFORMAS)
            ? (string) $request->query('plataforma')
            : 'mercado_livre';

        return Inertia::render('Admin/Pesquisas/Form', [
            'pesquisa' => null,
            'plataformaInicial' => $plataforma,
            'produtos' => $this->produtos(),
            'opcoes' => $this->opcoes(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $dados = $this->validar($request);

        $pesquisa = DB::transaction(function () use ($dados, $request) {
            $pesquisa = PesquisaMercado::create([
                ...collect($dados)->except('itens')->all(),
                'user_id' => $request->user()->id,
            ]);
            $this->salvarItens($pesquisa, $dados['itens'] ?? []);

            return $pesquisa;
        });

        return redirect()->route('admin.pesquisas.ver', $pesquisa)->with('success', 'Pesquisa salva.');
    }

    public function show(PesquisaMercado $pesquisa): Response
    {
        return Inertia::render('Admin/Pesquisas/Show', [
            'pesquisa' => $pesquisa->load(['autor:id,name', 'itens.product:id,name,sku,price']),
            'aba' => PesquisaMercado::abaDa($pesquisa->plataforma),
            'opcoes' => $this->opcoes(),
        ]);
    }

    public function edit(PesquisaMercado $pesquisa): Response
    {
        return Inertia::render('Admin/Pesquisas/Form', [
            'pesquisa' => $pesquisa->load('itens'),
            'plataformaInicial' => $pesquisa->plataforma,
            'produtos' => $this->produtos(),
            'opcoes' => $this->opcoes(),
        ]);
    }

    public function update(Request $request, PesquisaMercado $pesquisa): RedirectResponse
    {
        $dados = $this->validar($request);

        DB::transaction(function () use ($dados, $pesquisa) {
            $pesquisa->update(collect($dados)->except('itens')->all());
            $pesquisa->itens()->delete();
            $this->salvarItens($pesquisa, $dados['itens'] ?? []);
        });

        return redirect()->route('admin.pesquisas.ver', $pesquisa)->with('success', 'Pesquisa atualizada.');
    }

    /** Vai para a lixeira (soft delete): dá para recuperar pelo banco. */
    public function destroy(PesquisaMercado $pesquisa): RedirectResponse
    {
        $aba = PesquisaMercado::abaDa($pesquisa->plataforma);
        $pesquisa->delete();

        return redirect()->route('admin.pesquisas.listar', ['aba' => $aba])->with('success', 'Pesquisa removida.');
    }

    /** Aba Curva ABC: produtos de um canal pelas vendas efetivas. */
    public function curvaAbc(Request $request, CurvaAbcPorCanal $curva): Response
    {
        $canal = array_key_exists((string) $request->query('canal'), CurvaAbcPorCanal::CANAIS)
            ? (string) $request->query('canal')
            : 'mercado_livre';
        $dias = in_array((int) $request->query('dias'), [30, 60, 90, 180], true) ? (int) $request->query('dias') : 90;

        $ate = Carbon::tomorrow();
        $desde = Carbon::today()->subDays($dias - 1);

        return Inertia::render('Admin/Pesquisas/CurvaAbc', [
            'curva' => $curva->calcular($canal, $desde, $ate),
            'filtros' => ['canal' => $canal, 'dias' => $dias],
            'periodo' => ['desde' => $desde->toDateString(), 'ate' => $ate->copy()->subDay()->toDateString()],
            'canais' => CurvaAbcPorCanal::CANAIS,
            'contagem' => $this->contagemPorAba(),
        ]);
    }

    private function validar(Request $request): array
    {
        return $request->validate([
            'plataforma' => ['required', Rule::in(array_keys(PesquisaMercado::PLATAFORMAS))],
            'plataforma_nome' => ['nullable', 'required_if:plataforma,outra', 'string', 'max:80'],
            'titulo' => ['required', 'string', 'max:255'],
            'data_pesquisa' => ['required', 'date'],
            'status' => ['required', Rule::in(array_keys(PesquisaMercado::STATUS))],
            'resumo' => ['nullable', 'string', 'max:100000'],
            'itens' => ['nullable', 'array', 'max:200'],
            'itens.*.produto' => ['required', 'string', 'max:255'],
            'itens.*.link' => ['nullable', 'url', 'max:2048'],
            'itens.*.preco' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'itens.*.vendas' => ['nullable', 'string', 'max:120'],
            'itens.*.avaliacoes' => ['nullable', 'string', 'max:120'],
            'itens.*.vendedor' => ['nullable', 'string', 'max:255'],
            'itens.*.product_id' => ['nullable', 'integer', 'exists:products,id'],
            'itens.*.observacoes' => ['nullable', 'string', 'max:5000'],
            'itens.*.nota' => ['nullable', 'numeric', 'min:0', 'max:10'],
        ], [
            'plataforma_nome.required_if' => 'Diga qual é a plataforma.',
            'itens.*.produto.required' => 'Informe o nome do produto.',
            'itens.*.link.url' => 'O link precisa começar com http:// ou https://.',
            'itens.*.nota.max' => 'A nota vai de 0 a 10.',
        ]);
    }

    private function salvarItens(PesquisaMercado $pesquisa, array $itens): void
    {
        foreach (array_values($itens) as $posicao => $item) {
            $pesquisa->itens()->create([
                'posicao' => $posicao,
                'produto' => $item['produto'],
                'link' => $item['link'] ?? null,
                'preco' => $item['preco'] ?? null,
                'vendas' => $item['vendas'] ?? null,
                'avaliacoes' => $item['avaliacoes'] ?? null,
                'vendedor' => $item['vendedor'] ?? null,
                'product_id' => $item['product_id'] ?? null,
                'observacoes' => $item['observacoes'] ?? null,
                'nota' => $item['nota'] ?? null,
            ]);
        }
    }

    private function contagemPorAba(): array
    {
        $contagem = PesquisaMercado::query()
            ->selectRaw('plataforma, COUNT(*) as total')
            ->groupBy('plataforma')
            ->pluck('total', 'plataforma');

        return collect(PesquisaMercado::ABAS)
            ->map(fn ($plataformas) => (int) collect($plataformas)->sum(fn ($p) => $contagem[$p] ?? 0))
            ->all();
    }

    private function produtos(): array
    {
        return Product::query()
            ->whereNull('parent_product_id')
            ->orderBy('name')
            ->get(['id', 'name', 'sku'])
            ->all();
    }

    private function opcoes(): array
    {
        return [
            'plataformas' => PesquisaMercado::PLATAFORMAS,
            'status' => PesquisaMercado::STATUS,
        ];
    }
}
