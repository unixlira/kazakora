<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Pergunta do Mercado Livre que a Manuela não responde sozinha (a ficha do
 * anúncio não tem a informação, ou o Gemini falhou). Pedido do Lira
 * (2026-10-20): fica marcada pra ele, nada vai ao comprador, e ele é avisado.
 */
class PerguntaMercadoLivreSemRespostaNotification extends Notification
{
    public function __construct(
        private readonly int $questionId,
        private readonly string $itemId,
        private readonly string $pergunta,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'message' => "Pergunta no Mercado Livre pra você responder ({$this->itemId})",
            'body' => mb_substr($this->pergunta, 0, 300),
            'question_id' => $this->questionId,
            'nivel' => 'aviso',
        ];
    }
}
