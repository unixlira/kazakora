<?php

namespace App\Notifications;

use App\Modules\WhatsApp\Models\WhatsAppConversation;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * A Manuela ia dar uma resposta negativa ("não sei", "não tem") e, pela regra
 * do Lira (2026-10-25), não mandou nada ao cliente: o time de Atendimento
 * assume a conversa em WhatsApp > Conversas.
 */
class ManuelaAlertaAtendimentoNotification extends Notification
{
    public function __construct(
        private readonly WhatsAppConversation $conversation,
        private readonly string $pergunta,
        private readonly string $rascunho,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $cliente = $this->conversation->profile_name ?: $this->conversation->phone ?: $this->conversation->wa_id;
        $corpo = 'Cliente: "'.Str::limit(trim($this->pergunta), 200).'"';

        if (trim($this->rascunho) !== '') {
            $corpo .= ' Manuela ia responder: "'.Str::limit(trim($this->rascunho), 200).'"';
        }

        return [
            'message' => "Atendimento: a Manuela não soube responder {$cliente} e não enviou nada. Assuma a conversa.",
            'body' => $corpo,
            'link' => '/admin/whatsapp/conversas',
            'nivel' => 'aviso',
            'conversation_id' => $this->conversation->id,
        ];
    }
}
