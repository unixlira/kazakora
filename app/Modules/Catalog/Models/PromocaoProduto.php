<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Support\PromocoesDoProduto;
use App\Support\Rbac\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Promoção ligada a um produto (pai), pedido 2026-10-21. `ativo` é a chave
 * daquele produto: com a promoção em "todos os produtos", desligada tira o
 * quadro só dele; com "só os escolhidos", ligada é que faz aparecer.
 *
 * @property int $id
 * @property int $promocao_id
 * @property int $product_id
 * @property bool $ativo
 */
class PromocaoProduto extends Model
{
    use Auditable;

    protected $table = 'promocao_produto';

    protected $fillable = ['promocao_id', 'product_id', 'ativo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => PromocoesDoProduto::limparCache());
        static::deleted(fn () => PromocoesDoProduto::limparCache());
    }

    public function promocao(): BelongsTo
    {
        return $this->belongsTo(Promocao::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
