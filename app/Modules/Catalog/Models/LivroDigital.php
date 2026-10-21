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
 * @property bool $ativo
 * @property bool $todos_produtos
 */
class LivroDigital extends Model
{
    use Auditable;

    protected $table = 'livros_digitais';

    protected $fillable = ['titulo', 'arquivo', 'nome_arquivo', 'tamanho', 'capa', 'ativo', 'todos_produtos'];

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

    /** Ativos que aparecem na página do produto (variação vale o pai). */
    public static function doProduto(Product $produto): Collection
    {
        $ids = array_unique([$produto->id, $produto->parent_product_id ?? $produto->id]);

        return self::query()
            ->where('ativo', true)
            ->where(fn (Builder $q) => $q->where('todos_produtos', true)
                ->orWhereHas('produtos', fn (Builder $p) => $p->whereIn('products.id', $ids)))
            ->orderBy('id')
            ->get();
    }
}
