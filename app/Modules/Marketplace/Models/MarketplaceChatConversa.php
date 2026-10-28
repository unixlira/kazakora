<?php

namespace App\Modules\Marketplace\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conversa com comprador da Amazon (e-mail) ou do TikTok Shop, espelhada para o
 * admin. Desde 2026-10-27 também Direct do Instagram e Messenger do Facebook
 * (MetaMensagensService), cada rede no seu canal.
 */
class MarketplaceChatConversa extends Model
{
    public const AMAZON = 'amazon';

    public const TIKTOK = 'tiktok_shop';

    public const INSTAGRAM = 'instagram';

    public const FACEBOOK = 'facebook';

    public const CANAIS = [self::AMAZON, self::TIKTOK];

    /** Redes sociais da Meta (Atendimento › Instagram e Facebook). */
    public const REDES = [self::INSTAGRAM, self::FACEBOOK];

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
