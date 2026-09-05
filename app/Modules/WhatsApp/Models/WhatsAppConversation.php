<?php

namespace App\Modules\WhatsApp\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsAppConversation extends Model
{
    use HasFactory;

    // Laravel inferiria "whats_app_conversations" por causa do StudlyCase
    // WhatsApp. As migrations e o banco usam o prefixo operacional "whatsapp_*".
    protected $table = 'whatsapp_conversations';

    protected $fillable = [
        'wa_id',
        'phone',
        'profile_name',
        'status',
        'needs_human',
        'last_message_at',
        'last_customer_message_at',
        'last_auto_reply_at',
        'metadata',
    ];

    protected $casts = [
        'needs_human' => 'boolean',
        'last_message_at' => 'datetime',
        'last_customer_message_at' => 'datetime',
        'last_auto_reply_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class, 'conversation_id');
    }
}
