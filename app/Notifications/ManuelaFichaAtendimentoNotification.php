<?php

namespace App\Notifications;

use App\Modules\WhatsApp\Models\WhatsAppConversation;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Cliente pediu cancelamento e a Manuela já conversou com ele (pedido do Lira
 * 2026-11-14): o time recebe o aviso no sino com a ficha que ela preparou
 * (pedido, motivo, o que o cliente quer). A ficha completa fica na conversa.
 */
class ManuelaFichaAtendimentoNotification extends Notification
{
    public function __construct(
        private readonly WhatsAppConversation $conversation,
        private readonly string $ficha,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $cliente = $this->conversation->metadata['cliente_nome'] ?? $this->conversation->profile_name ?: $this->conversation->phone ?: $this->conversation->wa_id;

        return [
            'message' => "Atendimento: {$cliente} pediu cancelamento. A Manuela deixou a ficha pronta; assuma a conversa.",
            'body' => Str::limit(trim($this->ficha), 400),
            'link' => '/admin/whatsapp/conversas',
            'nivel' => 'aviso',
            'conversation_id' => $this->conversation->id,
        ];
    }
}
