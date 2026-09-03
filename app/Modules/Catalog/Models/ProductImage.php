<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Support\ProductImageThumbnailer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    protected $fillable = [
        'product_id',
        'path',
        'thumb_path',
        'position',
        'is_primary',
    ];

    protected $appends = ['url', 'thumb_url'];

    /**
     * O front nunca usa o caminho cru — usa `url` e `thumb_url`, que são
     * derivados dele. Escondê-los tira ~13 KB do payload da home (118
     * imagens x 2 caminhos) sem tirar nada de ninguém: `$hidden` só afeta
     * a serialização pra JSON, o acesso em PHP (OrderImageArchiveService,
     * publicação em canal) continua igual.
     */
    protected $hidden = ['path', 'thumb_path'];

    /**
     * Performance 2026-09-03: toda foto nova nasce com miniatura, venha de
     * upload do admin (ProductImageController) ou de importação de canal
     * (ShopeeMediaImportService) — em vez de cada chamador ter que lembrar
     * de gerar. Se a geração falhar, thumb_path fica null e thumb_url cai
     * pra original: nenhuma foto deixa de aparecer por causa disso.
     */
    protected static function booted(): void
    {
        static::creating(function (self $image) {
            if ($image->thumb_path === null && $image->path) {
                $image->thumb_path = ProductImageThumbnailer::generate($image->path);
            }
        });

        static::deleted(function (self $image) {
            if ($image->thumb_path) {
                Storage::disk('public')->delete($image->thumb_path);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_primary' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * A miniatura da vitrine quando existe, senão a original. Quem precisa
     * da foto em tamanho cheio (zoom da página de produto) usa `url`.
     */
    public function getThumbUrlAttribute(): string
    {
        if (! $this->thumb_path) {
            return $this->url;
        }

        return asset('storage/'.ltrim(preg_replace('#^storage/#', '', $this->thumb_path), '/'));
    }

    public function getUrlAttribute(): string
    {
        // Defensive: normalize away an accidental leading "storage/" so a
        // bad path value can't silently produce a broken storage/storage/...
        // URL — the real fix is keeping $path storage-relative in the first
        // place, this is just a safety net.
        return asset('storage/'.ltrim(preg_replace('#^storage/#', '', $this->path), '/'));
    }
}
