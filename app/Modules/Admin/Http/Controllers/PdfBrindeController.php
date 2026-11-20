<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\LivroDigital;
use App\Modules\Catalog\Models\PdfBrindeFila;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\PromocoesDoProduto;
use App\Modules\Catalog\Support\TemaDeBrinde;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Brindes em PDF (pedido do Lira 20/11/2026): a fila de criação dos PDFs e os
 * livros ligados a cada produto, tudo pelo admin, para o Lira remanejar sem
 * mexer no código (se a conexão com o Claude cair, ele faz por aqui).
 *
 * - aba "Brindes (PDF)" do produto: livros ligados (ligar/desligar), fila do
 *   produto (sugerir pelo tema, criar, editar, aprovar, devolver);
 * - Entregáveis › Fila de PDFs: a fila inteira e "montar para quem não tem".
 */
class PdfBrindeController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->query('status');

        $itens = PdfBrindeFila::query()
            ->with(['produto:id,name', 'produto.images', 'livro'])
            ->when(is_string($status) && isset(PdfBrindeFila::STATUS[$status]), fn ($q) => $q->where('status', $status))
            ->orderByRaw("CASE status WHEN 'revisao' THEN 0 WHEN 'produzindo' THEN 1 WHEN 'erro' THEN 2 WHEN 'fila' THEN 3 ELSE 4 END")
            ->orderBy('prioridade')
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->map(fn (PdfBrindeFila $item) => $item->resumo() + [
                'produto' => $item->produto ? LivroDigitalController::resumo($item->produto) : null,
            ]);

        $contagem = PdfBrindeFila::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return Inertia::render('Admin/Entregaveis/FilaPdfs', [
            'itens' => $itens,
            'contagem' => $contagem,
            'filtro' => $status,
            'tipos' => PdfBrindeFila::TIPOS,
            'status' => PdfBrindeFila::STATUS,
            'semBrinde' => $this->produtosSemBrinde()->count(),
        ]);
    }

    /** Monta a fila (pelo tema) de todos os produtos ativos que não têm livro próprio nem item na fila. */
    public function montarParaTodos(): RedirectResponse
    {
        $total = 0;
        foreach ($this->produtosSemBrinde()->get() as $produto) {
            $total += $this->criarSugestoes($produto);
        }

        return back()->with('success', $total ? "{$total} PDFs entraram na fila." : 'Todos os produtos já têm brinde ou fila.');
    }

    /** Dados da aba "Brindes (PDF)" do produto. */
    public function doProduto(Product $product): JsonResponse
    {
        $ids = array_unique([$product->id, $product->parent_product_id ?? $product->id]);

        $ligados = LivroDigital::query()
            ->where('todos_produtos', false)
            ->whereHas('produtos', fn ($q) => $q->whereIn('products.id', $ids))
            ->orderBy('id')
            ->get();

        $ligadosIds = $ligados->pluck('id')->all();

        return response()->json([
            'ligados' => $ligados->map(fn (LivroDigital $l) => self::livro($l))->values(),
            // Os outros livros, para ligar a este produto com um clique.
            'disponiveis' => LivroDigital::query()->whereNotIn('id', $ligadosIds)->orderByDesc('id')->get()
                ->map(fn (LivroDigital $l) => self::livro($l))->values(),
            // O que a página mostra hoje (os ligados ativos, senão os de "todos os produtos").
            'naPagina' => LivroDigital::doProduto($product)->map(fn (LivroDigital $l) => self::livro($l))->values(),
            'fila' => PdfBrindeFila::query()->with('livro')->where('product_id', $product->id)
                ->orderBy('prioridade')->orderBy('id')->get()->map(fn (PdfBrindeFila $i) => $i->resumo())->values(),
            'sugestoes' => TemaDeBrinde::sugerir($product),
            'tipos' => PdfBrindeFila::TIPOS,
        ]);
    }

    public function sugerir(Product $product): JsonResponse
    {
        $total = $this->criarSugestoes($product, mesmoComFila: true);

        return response()->json(['criados' => $total]);
    }

    public function store(Request $request, Product $product): JsonResponse
    {
        $item = PdfBrindeFila::query()->create($this->validar($request) + ['product_id' => $product->id, 'status' => 'fila']);

        return response()->json(['item' => $item->resumo()], 201);
    }

    public function update(Request $request, PdfBrindeFila $item): JsonResponse
    {
        $dados = $this->validar($request);
        if ($request->filled('status')) {
            $dados['status'] = $request->validate(['status' => [Rule::in(array_keys(PdfBrindeFila::STATUS))]])['status'];
        }
        $item->update($dados);

        return response()->json(['item' => $item->fresh('livro')->resumo()]);
    }

    /** Aprovado: o livro entregue passa a aparecer na página do produto. */
    public function aprovar(PdfBrindeFila $item): JsonResponse
    {
        abort_unless($item->livro, 422, 'Este item ainda não tem PDF entregue.');

        $item->livro->update(['ativo' => true]);
        $item->update(['status' => 'aprovado']);

        return response()->json(['item' => $item->fresh('livro')->resumo()]);
    }

    /** Devolve para a fila com o que mudar; o livro entregue sai da página. */
    public function devolver(Request $request, PdfBrindeFila $item): JsonResponse
    {
        $observacao = $request->validate(['observacao' => ['required', 'string', 'max:2000']], ['observacao.required' => 'Escreva o que mudar.'])['observacao'];

        $item->livro?->update(['ativo' => false]);
        $item->update(['status' => 'fila', 'observacao' => $observacao, 'pego_em' => null]);

        return response()->json(['item' => $item->fresh('livro')->resumo()]);
    }

    public function destroy(PdfBrindeFila $item): JsonResponse
    {
        $item->delete();

        return response()->json(['ok' => true]);
    }

    /** Liga um livro já cadastrado a este produto (deixa de valer para "todos"). */
    public function ligar(Product $product, LivroDigital $livro): JsonResponse
    {
        if ($livro->todos_produtos) {
            $livro->update(['todos_produtos' => false]);
        }
        $livro->produtos()->syncWithoutDetaching([$product->parent_product_id ?? $product->id]);
        PromocoesDoProduto::limparCache();

        return response()->json(['ok' => true]);
    }

    public function desligar(Product $product, LivroDigital $livro): JsonResponse
    {
        $livro->produtos()->detach(array_unique([$product->id, $product->parent_product_id ?? $product->id]));
        PromocoesDoProduto::limparCache();

        return response()->json(['ok' => true]);
    }

    /** @return array<string, mixed> */
    public static function livro(LivroDigital $livro): array
    {
        return [
            'id' => $livro->id,
            'titulo' => $livro->titulo,
            'capa_url' => $livro->capa_url,
            'ativo' => $livro->ativo,
            'todos_produtos' => $livro->todos_produtos,
            'nome_arquivo' => $livro->nome_arquivo,
        ];
    }

    private function criarSugestoes(Product $produto, bool $mesmoComFila = false): int
    {
        $existentes = PdfBrindeFila::query()->where('product_id', $produto->id)->pluck('titulo')->all();
        if ($existentes && ! $mesmoComFila) {
            return 0;
        }

        $total = 0;
        foreach (TemaDeBrinde::sugerir($produto) as $i => $sugestao) {
            if (in_array($sugestao['titulo'], $existentes, true)) {
                continue;
            }
            PdfBrindeFila::query()->create($sugestao + ['product_id' => $produto->id, 'status' => 'fila', 'prioridade' => 100 + $i]);
            $total++;
        }

        return $total;
    }

    private function produtosSemBrinde()
    {
        return Product::query()
            ->whereNull('parent_product_id')
            ->where('is_active', true)
            ->where('so_ferrakora', false)
            ->whereNotExists(fn ($q) => $q->from('pdf_brinde_fila')->whereColumn('pdf_brinde_fila.product_id', 'products.id'))
            ->whereNotExists(fn ($q) => $q->from('livro_digital_produto')->whereColumn('livro_digital_produto.product_id', 'products.id'))
            ->with('category');
    }

    /** @return array{tipo: string, titulo: string, briefing: ?string, paginas: int, prioridade?: int} */
    private function validar(Request $request): array
    {
        $dados = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(PdfBrindeFila::TIPOS))],
            'titulo' => ['required', 'string', 'max:255'],
            'briefing' => ['nullable', 'string', 'max:5000'],
            'paginas' => ['required', 'integer', 'min:4', 'max:80'],
            'prioridade' => ['nullable', 'integer', 'min:1', 'max:999'],
        ], [
            'titulo.required' => 'Dê um título ao PDF.',
            'paginas.min' => 'No mínimo 4 páginas.',
            'paginas.max' => 'No máximo 80 páginas.',
        ]);

        if (($dados['prioridade'] ?? null) === null) {
            unset($dados['prioridade']);
        }

        return $dados;
    }
}
