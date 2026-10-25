<?php

namespace App\Modules\Marketplace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Mensagem de uma conversa do chat da Shopee. */
class ShopeeChatMensagem extends Model
{
    protected $table = 'shopee_chat_mensagens';

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
        return $this->belongsTo(ShopeeChatConversa::class, 'conversa_id');
    }
}
