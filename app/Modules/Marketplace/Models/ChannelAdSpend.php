<?php

namespace App\Modules\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Gasto diário real com anúncio por canal (Shopee Ads, Mercado Ads) — ver
 * App\Services\Shopee\ShopeeAdsService / App\Services\MercadoLivre\
 * MercadoLivreAdsService e o comando ads:sync-spend.
 */
class ChannelAdSpend extends Model
{
    protected $fillable = [
        'date',
        'channel',
        'impressions',
        'clicks',
        'attributed_orders',
        'attributed_gmv',
        'spend',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'attributed_gmv' => 'decimal:2',
            'spend' => 'decimal:2',
        ];
    }
}
