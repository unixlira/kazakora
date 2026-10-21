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
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vender › Promoções (pedido 2026-10-21): Promoção Airfryer e Promoção PDFs.
 * Voltou para dentro de Vender (Lira, 2026-10-21) e Entregáveis saiu do
 * menu: o cadastro de sorteios e livros abre pelo link de cada card.
 * Cada uma tem título e descrição (página pública /promocoes/{slug}),
 * ativa/inativa, período, valor mínimo do pedido, observações e
 * chaves/regras livres que o Lira define.
 *
 * O menu abre direto esta lista (2026-10-21), que também cria promoções
 * novas (só texto, período e regras, sem quadro na página do produto).
 *
 * Texto e regras (2026-10-21): tela de cada promoção com o texto (título e
 * descrição com parágrafos) e as regras de negócio (período, pedido mínimo,
 * itens mínimos, limite por cliente, primeira compra, cupom e chaves livres).
 *
 * Tela inicial (2026-10-21): as duas lado a lado, cada uma com a chave
 * "mostrar na página do produto", a imagem do quadro (pronta, enviada ou a
 * de Entregáveis) e os produtos onde aparece (tabela promocao_produto).
 *
 * Texto do quadro (2026-10-21): título, texto, destaque (só livros),
 * condições ("pedidos pagos até...") e o botão para a página da promoção
 * também ficam no card, junto do resto do quadro.
 */
class PromocaoController extends Controller
{
    /** Caminhos que não podem virar promoção (/promocoes/sair, /admin/promocoes/nova). */
    private const SLUGS_RESERVADOS = ['nova', 'sair', 'editar'];

    public function index(): Response
    {
        $promocoes = Promocao::query()
            ->whereIn('slug', [Promocao::AIRFRYER, Promocao::PDFS])
            ->with('produtos.product.images')
            ->get()
            ->sortBy(fn (Promocao $promocao) => $promocao->slug === Promocao::AIRFRYER ? 0 : 1)
            ->map(fn (Promocao $promocao) => $this->card($promocao))
            ->values();

        // As criadas pela lista (2026-10-21): só texto, período e regras; o
        // quadro da página do produto é só do Airfryer e dos PDFs.
        $outras = Promocao::query()
            ->whereNotIn('slug', [Promocao::AIRFRYER, Promocao::PDFS])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Promocao $promocao) => [
                'slug' => $promocao->slug,
                'nome' => $promocao->nome,
                'titulo' => $promocao->titulo,
                'ativa' => $promocao->ativa,
                'no_ar' => $promocao->noAr(),
                'inicio' => $promocao->inicio?->format('d/m/Y H:i'),
                'fim' => $promocao->fim?->format('d/m/Y H:i'),
                'valor_minimo' => $promocao->valor_minimo,
                'regras' => count($promocao->regras ?? []),
            ]);

        return Inertia::render('Admin/Promocoes/Index', [
            'promocoes' => $promocoes,
            'outras' => $outras,
            'formatos' => PromocoesDoProduto::FORMATOS,
            'maxKb' => PromocoesDoProduto::MAX_KB,
        ]);
    }

    /** Nova promoção (2026-10-21): nasce inativa e abre na tela de texto e regras. */
    public function store(Request $request): RedirectResponse
    {
        $dados = $request->validate(
            ['nome' => ['required', 'string', 'max:255']],
            ['nome.required' => 'Dê um nome para a promoção.'],
        );
        $nome = trim($dados['nome']);

        $base = Str::limit(Str::slug($nome), 34, '') ?: 'promocao';
        $slug = $base;
        for ($n = 2; in_array($slug, self::SLUGS_RESERVADOS, true) || Promocao::query()->where('slug', $slug)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }

        $promocao = Promocao::query()->create(['slug' => $slug, 'nome' => $nome, 'ativa' => false]);

        return redirect("/admin/promocoes/{$promocao->slug}")->with('success', "{$promocao->nome} criada. Preencha o texto e as regras e ative quando quiser.");
    }

    /**
     * Excluir promoção (2026-10-21): apaga do banco, com as ligações de
     * produto (cascade) e a imagem enviada. Airfryer e PDFs não saem: sem a
     * linha deles o quadro da página do produto volta a aparecer em todos os
     * produtos, sem chave para desligar.
     */
    public function destroy(string $slug): RedirectResponse
    {
        $promocao = Promocao::query()->where('slug', $slug)->firstOrFail();

        if (in_array($promocao->slug, [Promocao::AIRFRYER, Promocao::PDFS], true)) {
            return back()->with('error', "{$promocao->nome} não pode ser excluída: ela controla o quadro da página do produto. Desligue em \"Mostrar na página do produto\".");
        }

        if ($promocao->imagem !== null && ! $promocao->imagemDoRepositorio()) {
            Storage::disk('public')->delete($promocao->imagem);
        }
        $promocao->delete();

        return redirect('/admin/promocoes')->with('success', "{$promocao->nome} excluída.");
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

    /** Título, texto, destaque, condições e botão do quadro (o botão leva a /promocoes/{slug}). */
    public function quadro(Request $request, string $slug): RedirectResponse
    {
        $promocao = Promocao::query()->where('slug', $slug)->firstOrFail();
        $dados = $request->validate([
            'quadro_titulo' => ['nullable', 'string', 'max:255'],
            'quadro_texto' => ['nullable', 'string', 'max:2000'],
            'quadro_destaque' => ['nullable', 'string', 'max:120'],
            'condicoes' => ['nullable', 'string', 'max:500'],
            'botao_ativo' => ['boolean'],
            'botao_texto' => ['nullable', 'string', 'max:60'],
        ], [
            'quadro_titulo.max' => 'O título pode ter no máximo 255 caracteres.',
            'quadro_texto.max' => 'O texto pode ter no máximo 2000 caracteres.',
            'quadro_destaque.max' => 'O destaque pode ter no máximo 120 caracteres.',
            'condicoes.max' => 'As condições podem ter no máximo 500 caracteres.',
            'botao_texto.max' => 'O texto do botão pode ter no máximo 60 caracteres.',
        ]);

        // Quebra de linha do navegador (\r\n) vira \n; vazio = texto de sempre.
        $limpo = fn (string $campo) => trim(str_replace(["\r\n", "\r"], "\n", (string) ($dados[$campo] ?? ''))) ?: null;
        $texto = trim((string) ($dados['botao_texto'] ?? ''));

        $promocao->update([
            'quadro_titulo' => $limpo('quadro_titulo'),
            'quadro_texto' => $limpo('quadro_texto'),
            // O destaque só existe no quadro dos livros.
            'quadro_destaque' => $slug === Promocao::PDFS ? $limpo('quadro_destaque') : null,
            'condicoes' => $limpo('condicoes'),
            'botao_ativo' => $request->boolean('botao_ativo'),
            'botao_texto' => $texto !== '' && $texto !== Promocao::BOTAO_PADRAO ? $texto : null,
        ]);

        $aviso = $promocao->botao_ativo && ! $promocao->noAr()
            ? ' O botão só aparece quando a página da promoção estiver no ar.'
            : '';

        return back()->with('success', "{$promocao->nome}: texto do quadro salvo.{$aviso}");
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

        // O que está cadastrado (sorteios ou livros), o texto que o quadro
        // usa com os campos vazios e a imagem quando a promoção não tem a sua.
        if ($slug === Promocao::AIRFRYER) {
            $ativos = Sorteio::query()->where('ativo', true)->orderBy('id')->get();
            $primeiro = $ativos->first();
            $padrao = $primeiro?->imagem_url ?? asset(PromocoesDoProduto::PRONTAS[$slug][0]['caminho']);
            $entregaveis = [
                'itens' => $ativos->pluck('titulo')->values(),
                'link' => '/admin/entregaveis/sorteios',
                'texto_sorteio' => $primeiro?->paragrafo_1,
                'titulo_padrao' => $primeiro?->titulo,
                'texto_padrao' => $primeiro ? trim($primeiro->paragrafo_1."\n\n".($primeiro->paragrafo_2 ?? '')) : null,
            ];
        } else {
            $ativos = LivroDigital::query()->where('ativo', true)->orderBy('id')->get();
            $padrao = asset(PromocoesDoProduto::QUADRO_LIVROS_PADRAO);
            $entregaveis = ['itens' => $ativos->pluck('titulo')->values(), 'link' => '/admin/entregaveis/livros-digitais'];
        }

        return [
            'slug' => $slug,
            'nome' => $promocao->nome,
            'ativa' => $promocao->ativa,
            'no_ar' => $promocao->noAr(),
            'inicio' => $promocao->inicio?->format('d/m/Y H:i'),
            'fim' => $promocao->fim?->format('d/m/Y H:i'),
            'fim_data' => $promocao->fim?->format('d/m/Y'),
            'condicoes' => $promocao->condicoes,
            'quadro_titulo' => $promocao->quadro_titulo,
            'quadro_texto' => $promocao->quadro_texto,
            'quadro_destaque' => $promocao->quadro_destaque,
            'botao_ativo' => $promocao->botao_ativo,
            'botao_texto' => $promocao->botao_texto,
            'botao_padrao' => Promocao::BOTAO_PADRAO,
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
                'itens_minimos' => $promocao->itens_minimos,
                'limite_por_cliente' => $promocao->limite_por_cliente,
                'primeira_compra' => $promocao->primeira_compra,
                'acumula_cupom' => $promocao->acumula_cupom,
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
            'itens_minimos' => ['nullable', 'integer', 'min:1', 'max:999'],
            'limite_por_cliente' => ['nullable', 'integer', 'min:1', 'max:999'],
            'primeira_compra' => ['boolean'],
            'acumula_cupom' => ['boolean'],
            'observacoes' => ['nullable', 'string', 'max:2000'],
            'regras' => ['nullable', 'array', 'max:50'],
            'regras.*.chave' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'regras.*.valor' => ['nullable', 'string', 'max:500'],
        ], [
            'fim.after_or_equal' => 'O fim precisa ser depois do início.',
            'itens_minimos.min' => 'Itens mínimos começa em 1 (vazio = sem mínimo).',
            'limite_por_cliente.min' => 'O limite começa em 1 (vazio = sem limite).',
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
            'itens_minimos' => $dados['itens_minimos'] ?? null,
            'limite_por_cliente' => $dados['limite_por_cliente'] ?? null,
            'primeira_compra' => $request->boolean('primeira_compra'),
            // Sem o campo (formulário antigo) continua acumulando, como antes.
            'acumula_cupom' => $request->boolean('acumula_cupom', true),
            'observacoes' => ($dados['observacoes'] ?? '') !== '' ? $dados['observacoes'] : null,
            'regras' => $regras ?: null,
        ]);

        return back()->with('success', "{$promocao->nome} salva.");
    }
}
