<?php

namespace App\Modules\WhatsApp\Models;

use App\Modules\WhatsApp\Support\WhatsAppPhone;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;

class WhatsAppConversation extends Model
{
    use HasFactory;

    // Laravel inferiria "whats_app_conversations" por causa do StudlyCase
    // WhatsApp. As migrations e o banco usam o prefixo operacional "whatsapp_*".
    protected $table = 'whatsapp_conversations';

    /** Loja dona do número (kazakora/ferrakora): a conversa responde sempre por ele. */
    protected $attributes = [
        'loja' => 'kazakora',
    ];

    protected $fillable = [
        'wa_id',
        'loja',
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

    /**
     * A chave acompanha o wa_id em qualquer gravação. O índice único
     * (numero_chave, loja) é o que garante uma conversa só por número.
     */
    public function setWaIdAttribute(?string $value): void
    {
        $this->attributes['wa_id'] = $value;
        $this->attributes['numero_chave'] = WhatsAppPhone::chave($value);
    }

    /** Conversa do número na loja, com ou sem o nono dígito. */
    public static function doNumero(?string $waId, string $loja): ?self
    {
        $chave = WhatsAppPhone::chave($waId);

        return $chave === null ? null : self::query()->where('loja', $loja)->where('numero_chave', $chave)->first();
    }

    /**
     * Único caminho para achar ou abrir conversa por número (webhook, disparo
     * de pedido, tela e alertas). Dois processos juntos: quem perde a corrida
     * no índice único usa a conversa que o outro criou.
     *
     * @param  array<string, mixed>  $dados  só usados se a conversa for nova
     */
    public static function paraNumero(string $waId, string $loja, array $dados = []): self
    {
        $conversa = self::doNumero($waId, $loja);
        if ($conversa) {
            return $conversa;
        }

        try {
            return self::query()->create(['wa_id' => $waId, 'loja' => $loja, ...$dados]);
        } catch (UniqueConstraintViolationException $exception) {
            return self::doNumero($waId, $loja) ?? throw $exception;
        }
    }

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
