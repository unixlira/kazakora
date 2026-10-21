<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\LivroDigital;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Promocao;
use App\Modules\Catalog\Models\PromocaoProduto;
use App\Modules\Catalog\Models\Sorteio;
use App\Modules\Catalog\Support\PromocoesDoProduto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vender › Promoções (pedido 2026-10-21): Promoção Airfryer e Promoção PDFs.
 * Cada uma tem título e descrição (página pública /promocoes/{slug}),
 * ativa/inativa, período, valor mínimo do pedido, observações e
 * chaves/regras livres que o Lira define.
 *
 * Tela inicial (2026-10-21): as duas lado a lado, cada uma com a chave
 * "mostrar na página do produto", a imagem do quadro (pronta, enviada ou a
 * de Entregáveis) e os produtos onde aparece (tabela promocao_produto).
 */
class PromocaoController extends Controller
{
    public function index(): Response
    {
        $promocoes = Promocao::query()
            ->whereIn('slug', [Promocao::AIRFRYER, Promocao::PDFS])
            ->with('produtos.product.images')
            ->get()
            ->sortBy(fn (Promocao $promocao) => $promocao->slug === Promocao::AIRFRYER ? 0 : 1)
            ->map(fn (Promocao $promocao) => $this->card($promocao))
            ->values();

        return Inertia::render('Admin/Promocoes/Index', [
            'promocoes' => $promocoes,
            'formatos' => PromocoesDoProduto::FORMATOS,
            'maxKb' => PromocoesDoProduto::MAX_KB,
        ]);
    }

    /** Chaves "mostrar na página do produto" e "todos os produtos". */
    public function paginaProduto(Request $request, string $slug): RedirectResponse
    {
        $promocao = Promocao::query()->where('slug', $slug)->firstOrFail();
        $dados = $request->validate([
            'na_pagina_produto' => ['sometimes', 'boolean'],
            'todos_produtos' => ['sometimes', 'boolean'],
        ]);
        $promocao->update($dados);

        $mensagem = array_key_exists('todos_produtos', $dados)
            ? ($promocao->todos_produtos ? 'aparece em todos os produtos (menos os desligados na lista).' : 'aparece só nos produtos ligados na lista.')
            : ($promocao->na_pagina_produto ? 'ligada na página do produto.' : 'desligada na página do produto.');

        return back()->with('success', "{$promocao->nome}: {$mensagem}");
    }

    /** Imagem do quadro: pronta do repositório, enviada agora ou '' (a de Entregáveis). */
    public function imagem(Request $request, string $slug): RedirectResponse
    {
        $promocao = Promocao::query()->where('slug', $slug)->firstOrFail();
        $dados = $request->validate([
            'imagem' => ['nullable', 'image', 'mimes:'.implode(',', PromocoesDoProduto::FORMATOS), 'max:'.PromocoesDoProduto::MAX_KB],
            'imagem_pronta' => ['nullable', 'string', Rule::in(array_column(PromocoesDoProduto::PRONTAS[$slug], 'caminho'))],
        ], [
            'imagem.image' => 'A imagem precisa ser JPG, PNG ou WebP.',
            'imagem.mimes' => 'A imagem precisa ser JPG, PNG ou WebP.',
            'imagem.max' => 'A imagem pode ter no máximo 5 MB.',
            'imagem_pronta.in' => 'Escolha uma das imagens da lista.',
        ]);

        $nova = $request->hasFile('imagem')
            ? $request->file('imagem')->store('promocoes', 'public')
            : (($dados['imagem_pronta'] ?? '') !== '' ? $dados['imagem_pronta'] : null);

        if ($nova !== $promocao->imagem) {
            if ($promocao->imagem !== null && ! $promocao->imagemDoRepositorio()) {
                Storage::disk('public')->delete($promocao->imagem);
            }
            $promocao->update(['imagem' => $nova]);
        }

        return back()->with('success', "{$promocao->nome}: imagem salva.");
    }

    /** Liga a promoção a um produto (variação vale o pai). */
    public function adicionarProduto(Request $request, string $slug): RedirectResponse
    {
        $promocao = Promocao::query()->where('slug', $slug)->firstOrFail();
        $dados = $request->validate(['product_id' => ['required', 'integer', 'exists:products,id']]);
        $produto = Product::query()->findOrFail($dados['product_id']);

        PromocaoProduto::query()->updateOrCreate(
            ['promocao_id' => $promocao->id, 'product_id' => $produto->parent_product_id ?? $produto->id],
            ['ativo' => true],
        );

        return back()->with('success', "{$promocao->nome}: produto adicionado.");
    }

    /** Chave do produto: liga / desliga o quadro só nele. */
    public function alternarProduto(string $slug, PromocaoProduto $ligacao): RedirectResponse
    {
        abort_unless($ligacao->promocao->slug === $slug, 404);
        $ligacao->update(['ativo' => ! $ligacao->ativo]);

        return back()->with('success', $ligacao->ativo ? 'Quadro ligado neste produto.' : 'Quadro desligado neste produto.');
    }

    public function removerProduto(string $slug, PromocaoProduto $ligacao): RedirectResponse
    {
        abort_unless($ligacao->promocao->slug === $slug, 404);
        $ligacao->delete();

        return back()->with('success', 'Produto tirado da lista.');
    }

    /** Dados de um card da tela inicial. */
    private function card(Promocao $promocao): array
    {
        $slug = $promocao->slug;

        // O que está cadastrado em Entregáveis (texto do quadro) e a imagem
        // que o quadro usa quando a promoção não tem a sua.
        if ($slug === Promocao::AIRFRYER) {
            $ativos = Sorteio::query()->where('ativo', true)->orderBy('id')->get();
            $padrao = $ativos->first()?->imagem_url ?? asset(PromocoesDoProduto::PRONTAS[$slug][0]['caminho']);
            $entregaveis = ['itens' => $ativos->pluck('titulo')->values(), 'link' => '/admin/entregaveis/sorteios'];
        } else {
            $ativos = LivroDigital::query()->where('ativo', true)->orderBy('id')->get();
            $padrao = asset(PromocoesDoProduto::QUADRO_LIVROS_PADRAO);
            $entregaveis = ['itens' => $ativos->pluck('titulo')->values(), 'link' => '/admin/entregaveis/livros-digitais'];
        }

        return [
            'slug' => $slug,
            'nome' => $promocao->nome,
            'ativa' => $promocao->ativa,
            'na_pagina_produto' => $promocao->na_pagina_produto,
            'todos_produtos' => $promocao->todos_produtos,
            'imagem' => $promocao->imagem,
            'imagem_url' => $promocao->imagem_url,
            'enviada' => $promocao->imagem !== null && ! $promocao->imagemDoRepositorio(),
            'padrao_url' => $padrao,
            'prontas' => array_map(fn (array $pronta) => $pronta + ['url' => asset($pronta['caminho'])], PromocoesDoProduto::PRONTAS[$slug]),
            'recomendado' => PromocoesDoProduto::TAMANHO_RECOMENDADO[$slug],
            'entregaveis' => $entregaveis,
            'produtos' => $promocao->produtos
                ->filter(fn (PromocaoProduto $ligacao) => $ligacao->product !== null)
                ->sortBy(fn (PromocaoProduto $ligacao) => $ligacao->product->name)
                ->map(fn (PromocaoProduto $ligacao) => LivroDigitalController::resumo($ligacao->product) + [
                    'ligacao_id' => $ligacao->id,
                    'ativo' => $ligacao->ativo,
                ])
                ->values(),
        ];
    }

    public function show(string $slug): Response
    {
        $promocao = Promocao::query()->where('slug', $slug)->firstOrFail();

        return Inertia::render('Admin/Promocoes/Editar', [
            'promocao' => [
                'slug' => $promocao->slug,
                'nome' => $promocao->nome,
                'titulo' => $promocao->titulo,
                'descricao' => $promocao->descricao,
                'ativa' => $promocao->ativa,
                'inicio' => $promocao->inicio?->format('Y-m-d\TH:i'),
                'fim' => $promocao->fim?->format('Y-m-d\TH:i'),
                'valor_minimo' => $promocao->valor_minimo,
                'observacoes' => $promocao->observacoes,
                'regras' => collect($promocao->regras ?? [])
                    ->map(fn ($valor, $chave) => ['chave' => (string) $chave, 'valor' => (string) $valor])
                    ->values(),
            ],
        ]);
    }

    public function update(Request $request, string $slug): RedirectResponse
    {
        $promocao = Promocao::query()->where('slug', $slug)->firstOrFail();

        $dados = $request->validate([
            'titulo' => ['nullable', 'string', 'max:255'],
            'descricao' => ['nullable', 'string', 'max:10000'],
            'ativa' => ['boolean'],
            'inicio' => ['nullable', 'date'],
            'fim' => ['nullable', 'date', 'after_or_equal:inicio'],
            'valor_minimo' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'observacoes' => ['nullable', 'string', 'max:2000'],
            'regras' => ['nullable', 'array', 'max:50'],
            'regras.*.chave' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'regras.*.valor' => ['nullable', 'string', 'max:500'],
        ], [
            'fim.after_or_equal' => 'O fim precisa ser depois do início.',
            'regras.*.chave.required' => 'Toda regra precisa de uma chave.',
            'regras.*.chave.regex' => 'A chave usa só letras, números, ponto, hífen e _.',
        ]);

        $regras = [];
        foreach ($dados['regras'] ?? [] as $regra) {
            $regras[$regra['chave']] = $regra['valor'] ?? '';
        }

        // Quebra de linha do navegador (\r\n) vira \n: o texto fica igual ao digitado.
        $descricao = trim(str_replace(["\r\n", "\r"], "\n", (string) ($dados['descricao'] ?? '')));

        $promocao->update([
            'titulo' => ($dados['titulo'] ?? '') !== '' ? $dados['titulo'] : null,
            'descricao' => $descricao !== '' ? $descricao : null,
            'ativa' => $request->boolean('ativa'),
            'inicio' => $dados['inicio'] ?? null,
            'fim' => $dados['fim'] ?? null,
            'valor_minimo' => $dados['valor_minimo'] ?? null,
            'observacoes' => ($dados['observacoes'] ?? '') !== '' ? $dados['observacoes'] : null,
            'regras' => $regras ?: null,
        ]);

        return back()->with('success', "{$promocao->nome} salva.");
    }
}
