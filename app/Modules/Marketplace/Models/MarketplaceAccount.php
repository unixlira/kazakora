<?php

namespace App\Modules\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplaceAccount extends Model
{
    public const CHANNEL_MERCADO_LIVRE = 'mercado_livre';

    public const CHANNEL_SHOPEE = 'shopee';

    public const CHANNEL_TIKTOK_SHOP = 'tiktok_shop';

    public const CHANNEL_AMAZON = 'amazon';

    public const STATUS_DISCONNECTED = 'disconnected';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_ERROR = 'error';

    protected $fillable = [
        'channel',
        'status',
        'seller_id',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'connected_at',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'connected_at' => 'datetime',
        ];
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED;
    }
}
