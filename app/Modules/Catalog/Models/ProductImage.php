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
        'medium_path',
        'mini_path',
        'celular_path',
        'micro_path',
        'position',
        'is_primary',
        'is_variant',
    ];

    protected $appends = ['url', 'thumb_url', 'media_url', 'mini_url'];

    /**
     * O front nunca usa o caminho cru — usa `url` e `thumb_url`, que são
     * derivados dele. Escondê-los tira ~13 KB do payload da home (118
     * imagens x 2 caminhos) sem tirar nada de ninguém: `$hidden` só afeta
     * a serialização pra JSON, o acesso em PHP (OrderImageArchiveService,
     * publicação em canal) continua igual.
     */
    protected $hidden = ['path', 'thumb_path', 'medium_path', 'mini_path', 'celular_path', 'micro_path'];

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
            if ($image->medium_path === null && $image->path) {
                $image->medium_path = ProductImageThumbnailer::generateMedium($image->path);
            }
            if ($image->mini_path === null && $image->path) {
                $image->mini_path = ProductImageThumbnailer::generateMini($image->path);
            }
            if ($image->celular_path === null && $image->path) {
                $image->celular_path = ProductImageThumbnailer::generateCelular($image->path);
            }
            if ($image->micro_path === null && $image->path) {
                $image->micro_path = ProductImageThumbnailer::generateMicro($image->path);
            }
        });

        static::deleted(function (self $image) {
            if ($image->thumb_path) {
                Storage::disk('public')->delete($image->thumb_path);
            }
            if ($image->medium_path) {
                Storage::disk('public')->delete($image->medium_path);
            }
            if ($image->mini_path) {
                Storage::disk('public')->delete($image->mini_path);
            }
            if ($image->celular_path) {
                Storage::disk('public')->delete($image->celular_path);
            }
            if ($image->micro_path) {
                Storage::disk('public')->delete($image->micro_path);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_primary' => 'boolean',
            'is_variant' => 'boolean',
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

    /**
     * Foto média da página de produto (foto grande e descrição) quando
     * existe, senão a original. O zoom continua na `url`.
     */
    public function getMediaUrlAttribute(): string
    {
        if (! $this->medium_path) {
            return $this->url;
        }

        return asset('storage/'.ltrim(preg_replace('#^storage/#', '', $this->medium_path), '/'));
    }

    /**
     * Foto pequena (miniaturas da galeria e das variações) quando existe,
     * senão a miniatura da vitrine.
     */
    public function getMiniUrlAttribute(): string
    {
        if (! $this->mini_path) {
            return $this->thumb_url;
        }

        return asset('storage/'.ltrim(preg_replace('#^storage/#', '', $this->mini_path), '/'));
    }

    /**
     * Foto de 800px do celular quando existe, senão a média. Fora do
     * `$appends` de propósito: só a página de produto usa (append() no
     * CatalogController::show), a home e a busca não pagam o peso no JSON.
     */
    public function getCelularUrlAttribute(): string
    {
        if (! $this->celular_path) {
            return $this->media_url;
        }

        return asset('storage/'.ltrim(preg_replace('#^storage/#', '', $this->celular_path), '/'));
    }

    /** Miniatura de 160px da galeria quando existe, senão a pequena de 320px. */
    public function getMicroUrlAttribute(): string
    {
        if (! $this->micro_path) {
            return $this->mini_url;
        }

        return asset('storage/'.ltrim(preg_replace('#^storage/#', '', $this->micro_path), '/'));
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
