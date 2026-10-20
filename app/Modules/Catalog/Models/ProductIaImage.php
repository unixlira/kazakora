<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Imagem do anúncio gerada pela IA, esperando revisão — ver a migration
 * create_product_ia_images_table e ImagensIaAnuncioService.
 */
class ProductIaImage extends Model
{
    public const STATUS_PENDENTE = 'pendente';

    public const STATUS_PRONTA = 'pronta';

    public const STATUS_ERRO = 'erro';

    public const STATUS_APROVADA = 'aprovada';

    protected $fillable = [
        'product_id',
        'lote',
        'ordem',
        'etapa',
        'titulo',
        'cena',
        'status',
        'path',
        'modelo',
        'erro',
        'product_image_id',
        'aprovada_em',
    ];

    protected $hidden = ['path'];

    protected $appends = ['url'];

    protected function casts(): array
    {
        return [
            'ordem' => 'integer',
            'aprovada_em' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getUrlAttribute(): ?string
    {
        return $this->path ? asset('storage/'.ltrim($this->path, '/')) : null;
    }
}
