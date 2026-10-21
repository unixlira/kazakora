<?php

namespace App\Modules\Catalog\Models;

use App\Support\Rbac\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Entregáveis › Livros Digitais (pedido 2026-10-20): PDF de brinde que
 * aparece na página do produto. O PDF fica no disco privado (`local`), só o
 * admin baixa; a capa (opcional) fica no `public`. `todos_produtos` = vale
 * para a loja inteira; senão só nos produtos de `produtos` (pais).
 *
 * @property int $id
 * @property string $titulo
 * @property string $arquivo
 * @property string $nome_arquivo
 * @property int $tamanho
 * @property ?string $capa
 * @property ?string $imagem_quadro
 * @property bool $ativo
 * @property bool $todos_produtos
 */
class LivroDigital extends Model
{
    use Auditable;

    protected $table = 'livros_digitais';

    protected $fillable = ['titulo', 'arquivo', 'nome_arquivo', 'tamanho', 'capa', 'imagem_quadro', 'ativo', 'todos_produtos'];

    protected function casts(): array
    {
        return [
            'tamanho' => 'integer',
            'ativo' => 'boolean',
            'todos_produtos' => 'boolean',
        ];
    }

    public function produtos(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'livro_digital_produto', 'livro_digital_id', 'product_id');
    }

    public function getCapaUrlAttribute(): ?string
    {
        return $this->capa ? asset('storage/'.$this->capa) : null;
    }

    /** Imagem dos tablets no quadro da página do produto (disco `public`). */
    public function getImagemQuadroUrlAttribute(): ?string
    {
        return $this->imagem_quadro ? asset('storage/'.$this->imagem_quadro) : null;
    }

    /**
     * Ativos que aparecem na página do produto (variação vale o pai). Se o
     * produto tem livros próprios (escolhidos para ele), mostra SÓ esses; os
     * de "todos os produtos" ficam para quem não tem nenhum (Lira,
     * 2026-10-21: guias de ferramentas não se misturam com os de costas).
     */
    public static function doProduto(Product $produto): Collection
    {
        $ids = array_unique([$produto->id, $produto->parent_product_id ?? $produto->id]);

        $especificos = self::query()
            ->where('ativo', true)
            ->where('todos_produtos', false)
            ->whereHas('produtos', fn (Builder $p) => $p->whereIn('products.id', $ids))
            ->orderBy('id')
            ->get();

        if ($especificos->isNotEmpty()) {
            return $especificos;
        }

        return self::query()
            ->where('ativo', true)
            ->where('todos_produtos', true)
            ->orderBy('id')
            ->get();
    }
}
