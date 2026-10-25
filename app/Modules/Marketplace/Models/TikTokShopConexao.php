<?php

namespace App\Modules\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;

/** Token OAuth do app próprio do TikTok Shop (uma linha: a loja conectada). */
class TikTokShopConexao extends Model
{
    protected $table = 'tiktok_shop_conexoes';

    protected $guarded = [];

    protected $hidden = ['access_token', 'refresh_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_expira_em' => 'datetime',
            'refresh_expira_em' => 'datetime',
        ];
    }

    public static function atual(): ?self
    {
        return static::query()->latest('id')->first();
    }
}
