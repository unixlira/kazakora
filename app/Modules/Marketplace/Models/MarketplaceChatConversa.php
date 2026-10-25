<?php

namespace App\Modules\Marketplace\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Conversa com comprador da Amazon (e-mail) ou do TikTok Shop, espelhada para o admin. */
class MarketplaceChatConversa extends Model
{
    public const AMAZON = 'amazon';

    public const TIKTOK = 'tiktok_shop';

    public const CANAIS = [self::AMAZON, self::TIKTOK];

    protected $table = 'marketplace_chat_conversas';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'ultima_do_cliente' => 'boolean',
            'ultima_mensagem_em' => 'datetime',
            'sincronizada_em' => 'datetime',
        ];
    }

    public function mensagens(): HasMany
    {
        return $this->hasMany(MarketplaceChatMensagem::class, 'conversa_id');
    }

    public function scopeDoCanal(Builder $query, string $canal): Builder
    {
        return $query->where('canal', $canal);
    }

    public function scopeComNaoLidas(Builder $query): Builder
    {
        return $query->where('nao_lidas', '>', 0);
    }
}
