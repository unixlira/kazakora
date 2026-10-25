<?php

namespace App\Modules\Marketplace\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Conversa do chat da Shopee (sellerchat) espelhada para o admin. */
class ShopeeChatConversa extends Model
{
    protected $table = 'shopee_chat_conversas';

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
        return $this->hasMany(ShopeeChatMensagem::class, 'conversa_id');
    }

    public function scopeComNaoLidas(Builder $query): Builder
    {
        return $query->where('nao_lidas', '>', 0);
    }
}
