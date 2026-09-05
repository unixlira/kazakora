<?php

namespace App\Modules\WhatsApp\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppMessage extends Model
{
    use HasFactory;

    // Mantém o nome igual ao criado pela migration; sem isso o Eloquent procura
    // "whats_app_messages" e a tela admin quebra antes de renderizar.
    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'conversation_id',
        'wa_message_id',
        'direction',
        'type',
        'body',
        'status',
        'payload',
        'sent_at',
        'received_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'sent_at' => 'datetime',
        'received_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsAppConversation::class, 'conversation_id');
    }
}
