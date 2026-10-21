<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\LivroDigital;
use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Entregáveis › Livros Digitais (pedido 2026-10-20): sobe o PDF, dá o título
 * e escolhe se aparece na página do produto (ativo) e em quais produtos
 * (todos ou os escolhidos). O PDF fica no disco privado; o admin baixa por
 * aqui.
 */
class LivroDigitalController extends Controller
{
    public function index(): Response
    {
        $livros = LivroDigital::query()
            ->with('produtos.images')
            ->orderByDesc('id')
            ->get()
            ->map(fn (LivroDigital $livro) => [
                'id' => $livro->id,
                'titulo' => $livro->titulo,
                'nome_arquivo' => $livro->nome_arquivo,
                'tamanho' => $livro->tamanho,
                'capa_url' => $livro->capa_url,
                'ativo' => $livro->ativo,
                'todos_produtos' => $livro->todos_produtos,
                'produtos' => $livro->produtos->map(fn (Product $produto) => self::resumo($produto))->values(),
            ]);

        return Inertia::render('Admin/Entregaveis/LivrosDigitais', ['livros' => $livros]);
    }

    /** Busca de produto (pais ativos) para Livros Digitais e Sorteios. */
    public function produtos(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        $produtos = Product::query()
            ->whereNull('parent_product_id')
            ->where('is_active', true)
            ->when($q !== '', function (Builder $builder) use ($q) {
                $like = '%'.addcslashes($q, '\\%_').'%';
                $builder->where(fn (Builder $w) => $w->where('name', 'like', $like)->orWhere('sku', 'like', $like));
            })
            ->with('images')
            ->orderBy('name')
            ->limit(20)
            ->get()
            ->map(fn (Product $produto) => self::resumo($produto))
            ->values();

        return response()->json(['produtos' => $produtos]);
    }

    public function store(Request $request): RedirectResponse
    {
        $dados = $this->validar($request, novo: true);
        $pdf = $request->file('pdf');

        $livro = LivroDigital::query()->create([
            'titulo' => $dados['titulo'],
            'arquivo' => $pdf->store('livros-digitais', 'local'),
            'nome_arquivo' => $pdf->getClientOriginalName(),
            'tamanho' => $pdf->getSize(),
            'capa' => $request->hasFile('capa') ? $request->file('capa')->store('livros-digitais', 'public') : null,
            'ativo' => $request->boolean('ativo', true),
            'todos_produtos' => $request->boolean('todos_produtos', true),
        ]);
        $livro->produtos()->sync($livro->todos_produtos ? [] : $dados['produtos']);

        return redirect()->route('admin.entregaveis.livros.listar')->with('success', 'Livro digital cadastrado.');
    }

    public function update(Request $request, LivroDigital $livro): RedirectResponse
    {
        $dados = $this->validar($request, novo: false);
        $campos = [
            'titulo' => $dados['titulo'],
            'ativo' => $request->boolean('ativo'),
            'todos_produtos' => $request->boolean('todos_produtos'),
        ];

        if ($request->hasFile('pdf')) {
            Storage::disk('local')->delete($livro->arquivo);
            $pdf = $request->file('pdf');
            $campos += [
                'arquivo' => $pdf->store('livros-digitais', 'local'),
                'nome_arquivo' => $pdf->getClientOriginalName(),
                'tamanho' => $pdf->getSize(),
            ];
        }

        if ($request->hasFile('capa')) {
            $livro->capa && Storage::disk('public')->delete($livro->capa);
            $campos['capa'] = $request->file('capa')->store('livros-digitais', 'public');
        } elseif ($request->boolean('remover_capa') && $livro->capa) {
            Storage::disk('public')->delete($livro->capa);
            $campos['capa'] = null;
        }

        $livro->update($campos);
        $livro->produtos()->sync($livro->todos_produtos ? [] : $dados['produtos']);

        return redirect()->route('admin.entregaveis.livros.listar')->with('success', 'Livro digital atualizado.');
    }

    /** Liga / desliga na página do produto. */
    public function alternar(LivroDigital $livro): RedirectResponse
    {
        $livro->update(['ativo' => ! $livro->ativo]);

        return back()->with('success', $livro->ativo ? 'Livro ativado na página do produto.' : 'Livro desativado na página do produto.');
    }

    public function baixar(LivroDigital $livro): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($livro->arquivo), 404);

        return Storage::disk('local')->download($livro->arquivo, $livro->nome_arquivo);
    }

    public function destroy(LivroDigital $livro): RedirectResponse
    {
        Storage::disk('local')->delete($livro->arquivo);
        $livro->capa && Storage::disk('public')->delete($livro->capa);
        $livro->delete();

        return back()->with('success', 'Livro digital excluído.');
    }

    /** @return array{titulo: string, produtos: list<int>} */
    private function validar(Request $request, bool $novo): array
    {
        $dados = $request->validate([
            'titulo' => ['required', 'string', 'max:255'],
            'pdf' => [$novo ? 'required' : 'nullable', 'file', 'mimes:pdf', 'max:51200'],
            'capa' => ['nullable', 'image', 'max:5120'],
            'todos_produtos' => ['nullable', 'boolean'],
            'produtos' => [$request->boolean('todos_produtos', $novo) ? 'nullable' : 'required', 'array'],
            'produtos.*' => ['integer', 'exists:products,id'],
        ], [
            'titulo.required' => 'Dê um título ao livro.',
            'pdf.required' => 'Escolha o arquivo PDF.',
            'pdf.mimes' => 'O arquivo precisa ser PDF.',
            'pdf.max' => 'O PDF pode ter no máximo 50 MB.',
            'capa.image' => 'A capa precisa ser uma imagem (JPG, PNG ou WebP).',
            'capa.max' => 'A capa pode ter no máximo 5 MB.',
            'produtos.required' => 'Escolha pelo menos um produto ou marque "Todos os produtos".',
        ]);

        return ['titulo' => $dados['titulo'], 'produtos' => array_values(array_unique(array_map('intval', $dados['produtos'] ?? [])))];
    }

    /** @return array{id: int, nome: string, sku: ?string, foto: ?string} */
    public static function resumo(Product $produto): array
    {
        return [
            'id' => $produto->id,
            'nome' => $produto->name,
            'sku' => $produto->sku,
            'foto' => $produto->images->first()?->url,
        ];
    }
}
