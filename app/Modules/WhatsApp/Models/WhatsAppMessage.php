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

    /**
     * Reação com emoji a uma mensagem (pedido do Lira 04/11), do cliente ou
     * do atendente. Emoji vazio = tirou a reação. `alvo` = wa_message_id.
     *
     * @return array{emoji: string, alvo: ?string}|null
     */
    public function reacao(): ?array
    {
        if ($this->type !== 'reaction') {
            return null;
        }

        $reaction = $this->payload['reaction'] ?? [];

        return ['emoji' => (string) ($reaction['emoji'] ?? ''), 'alvo' => $reaction['message_id'] ?? null];
    }

    /** Texto curto pra lista de conversas (igual ao WhatsApp: "📷 Foto"). */
    public function preview(): string
    {
        if ($reacao = $this->reacao()) {
            if ($reacao['emoji'] === '') {
                return 'Removeu a reação';
            }

            return ($this->direction === 'outbound' ? 'Você reagiu com ' : 'Reagiu com ').$reacao['emoji'];
        }

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

    /** Cópia local (storage/app/private) do que o atendente mandou pela tela. */
    public function localMediaPath(): ?string
    {
        return $this->payload['local_path'] ?? null;
    }

    public function hasMedia(): bool
    {
        return $this->mediaId() !== null || $this->localMediaPath() !== null;
    }
}
