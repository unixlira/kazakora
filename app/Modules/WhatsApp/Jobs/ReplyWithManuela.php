<?php

namespace App\Modules\WhatsApp\Jobs;

use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use App\Modules\WhatsApp\Services\ManuelaAutoReplyService;
use App\Modules\WhatsApp\Services\WhatsAppOutbox;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Despachado com afterResponse() pelo webhook: a Meta recebe o 200 na hora
 * e a Manuela (Hermes pode levar 10-40s) responde no mesmo processo logo
 * depois. Fila não serve aqui — o worker do homolog só roda ~1x/min.
 */
class ReplyWithManuela
{
    use Dispatchable;

    public function __construct(public int $conversationId, public int $inboundMessageId)
    {
    }

    public function handle(ManuelaAutoReplyService $manuela, WhatsAppOutbox $outbox): void
    {
        @set_time_limit(150);

        $conversation = WhatsAppConversation::query()->find($this->conversationId);
        $inbound = WhatsAppMessage::query()->find($this->inboundMessageId);

        if (! $conversation || ! $inbound || ! $conversation->ai_enabled || $conversation->needs_human) {
            return;
        }

        // Cliente mandou várias mensagens seguidas: só a última dispara
        // resposta (ela já leva o histórico inteiro pra Manuela). E se alguém
        // (Manuela ou pessoa) já respondeu depois dela, não responde de novo.
        $alreadyHandled = $conversation->messages()
            ->whereIn('direction', ['inbound', 'outbound'])
            ->where('id', '>', $inbound->id)
            ->exists();

        if ($alreadyHandled) {
            return;
        }

        if ($inbound->type !== 'text' || ! filled($inbound->body)) {
            $conversation->update(['needs_human' => true, 'status' => 'needs_human']);

            return;
        }

        $reply = $manuela->buildReply($conversation, $inbound->body);

        // Uma pessoa assumiu enquanto a Manuela pensava: não atropela.
        if (! $conversation->fresh()->ai_enabled) {
            return;
        }

        if ($reply['needs_human'] ?? false) {
            $conversation->update(['needs_human' => true, 'status' => 'needs_human']);
        }

        $outbox->sendText($conversation->fresh(), $reply['reply'], 'manuela', null, ['manuela' => $reply]);
        $conversation->update(['last_auto_reply_at' => now()]);
    }
}
