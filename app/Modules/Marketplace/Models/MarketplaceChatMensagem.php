<?php

namespace App\Modules\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Mensagem de uma conversa da Amazon ou do TikTok Shop. */
class MarketplaceChatMensagem extends Model
{
    protected $table = 'marketplace_chat_mensagens';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'do_cliente' => 'boolean',
            'conteudo' => 'array',
            'enviada_em' => 'datetime',
        ];
    }

    public function conversa(): BelongsTo
    {
        return $this->belongsTo(MarketplaceChatConversa::class, 'conversa_id');
    }
}
