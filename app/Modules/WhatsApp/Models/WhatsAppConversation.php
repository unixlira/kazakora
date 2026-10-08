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
        'unread_count',
        'ai_enabled',
        'last_message_preview',
        'last_message_direction',
    ];

    protected $casts = [
        'needs_human' => 'boolean',
        'ai_enabled' => 'boolean',
        'unread_count' => 'integer',
        'last_message_at' => 'datetime',
        'last_customer_message_at' => 'datetime',
        'last_auto_reply_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsAppMessage::class, 'conversation_id');
    }

    /**
     * Atualiza o que a lista da tela de Conversas mostra (prévia, horário e
     * não lidas). Toda mensagem nova passa por aqui — webhook, Manuela e
     * resposta humana — pra lista nunca ficar defasada do chat.
     */
    public function registerMessage(WhatsAppMessage $message): void
    {
        $at = $message->received_at ?? $message->sent_at ?? $message->created_at ?? now();

        $this->forceFill([
            'last_message_at' => $at,
            'last_message_preview' => $message->preview(),
            'last_message_direction' => $message->direction,
            'unread_count' => $message->direction === 'inbound' ? $this->unread_count + 1 : $this->unread_count,
        ])->save();
    }

    /** Janela de 24h da Meta: fora dela só template, texto livre é recusado. */
    public function insideServiceWindow(): bool
    {
        return $this->last_customer_message_at !== null
            && $this->last_customer_message_at->gt(now()->subDay());
    }
}
