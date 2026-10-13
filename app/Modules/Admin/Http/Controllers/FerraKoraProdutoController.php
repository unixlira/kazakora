<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sistema › Produtos FerraKora (pedido 2026-10-13): a tabela dos produtos que
 * aparecem no site FerraKora (ferrakora_produtos), paginada, com busca, filtro
 * por categoria/situação e edição da categoria e do "ativo" na linha. Desativar
 * só tira do site; o produto continua no KazaKora.
 */
class FerraKoraProdutoController extends Controller
{
    private const POR_PAGINA = 25;

    private const SEM_CATEGORIA = '__sem';

    public function index(Request $request): Response
    {
        $filtros = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'categoria' => ['nullable', 'string', 'max:100'],
            'ativo' => ['nullable', 'in:0,1'],
        ]);

        $q = trim((string) ($filtros['q'] ?? ''));
        $categoria = (string) ($filtros['categoria'] ?? '');
        $ativo = $filtros['ativo'] ?? '';

        $query = FerraKoraProduto::query()
            ->with('product:id,sku,name')
            ->when($q !== '', function (Builder $builder) use ($q) {
                $like = '%'.addcslashes($q, '\\%_').'%';
                $builder->where(fn (Builder $w) => $w
                    ->where('titulo', 'like', $like)
                    ->orWhereHas('product', fn (Builder $p) => $p->where('name', 'like', $like)->orWhere('sku', 'like', $like)));
            })
            ->when($categoria === self::SEM_CATEGORIA, fn (Builder $b) => $b->where(fn (Builder $w) => $w->whereNull('categoria')->orWhere('categoria', '')))
            ->when($categoria !== '' && $categoria !== self::SEM_CATEGORIA, fn (Builder $b) => $b->where('categoria', $categoria))
            ->when($ativo !== '', fn (Builder $b) => $b->where('ativo', $ativo === '1'));

        $produtos = $query->orderBy('ordem')->orderBy('id')
            ->paginate(self::POR_PAGINA)
            ->withQueryString()
            ->through(fn (FerraKoraProduto $registro) => [
                'id' => $registro->id,
                'sku' => $registro->product?->sku,
                'nome' => $registro->titulo ?: $registro->product?->name,
                'categoria' => $registro->categoria,
                'ativo' => $registro->ativo,
            ]);

        return Inertia::render('Admin/FerraKoraProdutos/Index', [
            'produtos' => $produtos,
            'filtros' => ['q' => $q, 'categoria' => $categoria, 'ativo' => (string) $ativo],
            'categorias' => FerraKoraProduto::query()->whereNotNull('categoria')->where('categoria', '!=', '')
                ->distinct()->orderBy('categoria')->pluck('categoria')->values(),
            'totais' => [
                'todos' => FerraKoraProduto::query()->count(),
                'ativos' => FerraKoraProduto::query()->where('ativo', true)->count(),
                'sem_categoria' => FerraKoraProduto::query()->where(fn (Builder $w) => $w->whereNull('categoria')->orWhere('categoria', ''))->count(),
            ],
        ]);
    }

    public function update(Request $request, FerraKoraProduto $registro): RedirectResponse
    {
        $dados = $request->validate([
            'categoria' => ['sometimes', 'nullable', 'string', 'max:100'],
            'ativo' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('categoria', $dados)) {
            $dados['categoria'] = filled($dados['categoria']) ? trim($dados['categoria']) : null;
        }

        $registro->update($dados);

        return back();
    }
}
