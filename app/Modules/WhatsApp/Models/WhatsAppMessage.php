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
        'sent_by',
        'user_id',
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

    /** Texto curto pra lista de conversas (igual ao WhatsApp: "📷 Foto"). */
    public function preview(): string
    {
        $label = match ($this->type) {
            'image' => '📷 Foto',
            'video' => '🎥 Vídeo',
            'audio' => '🎤 Áudio',
            'document' => '📄 Documento',
            'sticker' => 'Figurinha',
            'location' => '📍 Localização',
            'template' => '📣 Modelo',
            'unsupported' => '⚠️ Mensagem não suportada',
            default => null,
        };

        $body = trim((string) $this->body);
        $text = $label && $body !== '' ? "{$label}: {$body}" : ($label ?? $body);

        return mb_strimwidth($text !== '' ? $text : 'Mensagem', 0, 250, '…');
    }

    /** ID da mídia na Meta (imagem, áudio, vídeo, documento, figurinha). */
    public function mediaId(): ?string
    {
        $payload = $this->payload ?? [];

        return $payload[$this->type]['id'] ?? null;
    }
}
