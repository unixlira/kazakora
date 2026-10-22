<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Promocao;
use App\Modules\Catalog\Models\Sorteio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Entregáveis › Sorteios (pedido 2026-10-20): produto onde o sorteio aparece
 * (ou todos), foto do prêmio, título e dois parágrafos. Ativa e desativa a
 * qualquer momento. A busca de produto é a mesma dos Livros Digitais.
 */
class SorteioController extends Controller
{
    public function index(): Response
    {
        $sorteios = Sorteio::query()
            ->with('product.images')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Sorteio $sorteio) => [
                'id' => $sorteio->id,
                'product_id' => $sorteio->product_id,
                'produto' => $sorteio->product ? LivroDigitalController::resumo($sorteio->product) : null,
                'titulo' => $sorteio->titulo,
                'paragrafo_1' => $sorteio->paragrafo_1,
                'paragrafo_2' => $sorteio->paragrafo_2,
                'imagem_url' => $sorteio->imagem_url,
                'ativo' => $sorteio->ativo,
            ]);

        // Com condições na Promoção Airfryer, elas substituem os parágrafos no
        // quadro (2026-10-21): a tela avisa para não editar no lugar errado.
        $airfryer = Promocao::query()->where('slug', Promocao::AIRFRYER)->first();

        return Inertia::render('Admin/Entregaveis/Sorteios', [
            'sorteios' => $sorteios,
            'condicoesPromocao' => $airfryer?->condicoes,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $dados = $this->validar($request, novo: true);
        $dados['imagem'] = $request->file('imagem')->store('sorteios', 'public');
        $dados['ativo'] = $request->boolean('ativo', true);

        Sorteio::query()->create($dados);

        return redirect()->route('admin.entregaveis.sorteios.listar')->with('success', 'Sorteio cadastrado.');
    }

    public function update(Request $request, Sorteio $sorteio): RedirectResponse
    {
        $dados = $this->validar($request, novo: false);
        $dados['ativo'] = $request->boolean('ativo');

        if ($request->hasFile('imagem')) {
            $this->apagarImagem($sorteio);
            $dados['imagem'] = $request->file('imagem')->store('sorteios', 'public');
        }

        $sorteio->update($dados);

        return redirect()->route('admin.entregaveis.sorteios.listar')->with('success', 'Sorteio atualizado.');
    }

    /** Liga / desliga na página do produto. */
    public function alternar(Sorteio $sorteio): RedirectResponse
    {
        $sorteio->update(['ativo' => ! $sorteio->ativo]);

        return back()->with('success', $sorteio->ativo ? 'Sorteio ativado.' : 'Sorteio desativado.');
    }

    public function destroy(Sorteio $sorteio): RedirectResponse
    {
        $this->apagarImagem($sorteio);
        $sorteio->delete();

        return back()->with('success', 'Sorteio excluído.');
    }

    private function apagarImagem(Sorteio $sorteio): void
    {
        if (! $sorteio->imagemDoRepositorio()) {
            Storage::disk('public')->delete($sorteio->imagem);
        }
    }

    /** @return array{product_id: ?int, titulo: string, paragrafo_1: string, paragrafo_2: ?string} */
    private function validar(Request $request, bool $novo): array
    {
        $dados = $request->validate([
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'titulo' => ['required', 'string', 'max:255'],
            'paragrafo_1' => ['required', 'string', 'max:2000'],
            'paragrafo_2' => ['nullable', 'string', 'max:2000'],
            'imagem' => [$novo ? 'required' : 'nullable', 'image', 'max:5120'],
        ], [
            'titulo.required' => 'Dê um título ao sorteio.',
            'paragrafo_1.required' => 'Escreva o primeiro parágrafo.',
            'imagem.required' => 'Escolha a imagem do prêmio.',
            'imagem.image' => 'A imagem precisa ser JPG, PNG ou WebP.',
            'imagem.max' => 'A imagem pode ter no máximo 5 MB.',
        ]);

        // Variação escolhida por engano vale o pai (a página é do grupo).
        $produtoId = null;
        if (! empty($dados['product_id'])) {
            $produto = Product::query()->find($dados['product_id']);
            $produtoId = $produto->parent_product_id ?? $produto->id;
        }

        return [
            'product_id' => $produtoId,
            'titulo' => $dados['titulo'],
            'paragrafo_1' => $dados['paragrafo_1'],
            'paragrafo_2' => ($dados['paragrafo_2'] ?? '') !== '' ? $dados['paragrafo_2'] : null,
        ];
    }
}
