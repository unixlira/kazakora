<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Support\PromocoesDoProduto;
use App\Support\Rbac\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entregáveis › Sorteios (pedido 2026-10-20): banner de sorteio na página do
 * produto (título, dois parágrafos e a foto do prêmio). `product_id` null =
 * todos os produtos. A imagem fica no disco `public`; a do primeiro sorteio
 * (AirFryer, que era fixo na página) ficou em `public/images/promocoes`.
 *
 * @property int $id
 * @property ?int $product_id
 * @property string $titulo
 * @property string $paragrafo_1
 * @property ?string $paragrafo_2
 * @property string $imagem
 * @property bool $ativo
 */
class Sorteio extends Model
{
    use Auditable;

    protected $fillable = ['product_id', 'titulo', 'paragrafo_1', 'paragrafo_2', 'imagem', 'ativo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => PromocoesDoProduto::limparCache());
        static::deleted(fn () => PromocoesDoProduto::limparCache());
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Imagem que veio do repositório (public/images), não de upload. */
    public function imagemDoRepositorio(): bool
    {
        return str_starts_with($this->imagem, 'images/');
    }

    public function getImagemUrlAttribute(): string
    {
        return $this->imagemDoRepositorio() ? asset($this->imagem) : asset('storage/'.$this->imagem);
    }

    /** Ativos que aparecem na página do produto (variação vale o pai). */
    public static function doProduto(Product $produto): Collection
    {
        $ids = array_unique([$produto->id, $produto->parent_product_id ?? $produto->id]);

        return self::query()
            ->where('ativo', true)
            ->where(fn (Builder $q) => $q->whereNull('product_id')->orWhereIn('product_id', $ids))
            ->orderBy('id')
            ->get();
    }
}
