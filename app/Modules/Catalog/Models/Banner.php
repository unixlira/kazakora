<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Catalog\Support\BannerMobileOptimizer;
use App\Support\Rbac\Auditable;
use Illuminate\Database\Eloquent\Model;

class Banner extends Model
{
    use Auditable;

    protected $fillable = [
        'title',
        'image_path',
        'image_path_mobile',
        'link_url',
        'sort_order',
        'is_active',
    ];

    protected $appends = ['image_url', 'image_url_mobile', 'image_srcset_mobile'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function getImageUrlAttribute(): string
    {
        return asset('storage/'.$this->image_path);
    }

    public function getImageUrlMobileAttribute(): ?string
    {
        return $this->image_path_mobile ? asset('storage/'.$this->image_path_mobile) : null;
    }

    public function getImageSrcsetMobileAttribute(): ?string
    {
        return $this->image_path_mobile ? BannerMobileOptimizer::srcset($this->image_path_mobile) : null;
    }
}
