<?php

namespace App\Modules\WhatsApp\Services;

use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use App\Modules\WhatsApp\Support\WhatsAppSettings;
use Illuminate\Support\Str;

/**
 * Acompanhamento automático (pedido 2026-10-20): a Manuela respondeu e o
 * cliente sumiu por mais de 30 minutos → uma mensagem amigável perguntando
 * se ainda tem dúvida. Uma só por silêncio: a própria mensagem vira a última
 * da conversa, então só volta a valer depois que o cliente responder e a
 * Manuela responder de novo.
 */
class ManuelaFollowUp
{
    public const SENT_BY = 'manuela';

    /** Horário (Brasília) em que o acompanhamento pode sair; fora dele espera a manhã. */
    private const HORA_INICIAL = 8;

    private const HORA_FINAL = 21;

    public function __construct(
        private readonly WhatsAppSettings $settings,
        private readonly WhatsAppOutbox $outbox,
    ) {
    }

    /** @return int quantos acompanhamentos foram enviados */
    public function run(): int
    {
        if (! $this->settings->bool('followup_enabled') || ! $this->settings->bool('auto_reply_enabled') || ! $this->dentroDoHorario()) {
            return 0;
        }

        $minutos = max(5, (int) $this->settings->get('followup_minutes'));
        $enviados = 0;

        // Texto livre só vale dentro das 24h da Meta, contadas da última mensagem do cliente.
        WhatsAppConversation::query()
            ->where('ai_enabled', true)
            ->where('needs_human', false)
            ->where('status', '!=', 'resolved')
            ->where('last_message_direction', 'outbound')
            ->where('last_message_at', '<=', now()->subMinutes($minutos))
            ->where('last_customer_message_at', '>', now()->subDay())
            ->each(function (WhatsAppConversation $conversation) use ($minutos, &$enviados) {
                if ($this->deveAcompanhar($conversation, $minutos)) {
                    $this->enviar($conversation);
                    $enviados++;
                }
            });

        return $enviados;
    }

    public function deveAcompanhar(WhatsAppConversation $conversation, int $minutos): bool
    {
        $ultima = $this->ultimaMensagem($conversation);

        // A última tem que ser resposta da Manuela que chegou ao cliente
        // (nem pessoa, nem acompanhamento anterior, nem envio que falhou).
        if (! $ultima
            || $ultima->direction !== 'outbound'
            || $ultima->sent_by !== 'manuela'
            || in_array($ultima->status, ['failed', 'draft_no_token'], true)
            || ($ultima->payload['followup'] ?? false)
            || ($ultima->sent_at ?? $ultima->created_at)->gt(now()->subMinutes($minutos))) {
            return false;
        }

        // Pedido para chamar uma pessoa: quem segue é o time, não a Manuela.
        if (trim((string) $ultima->body) === ManuelaAutoReplyService::HANDOFF_REPLY
            || ($ultima->payload['manuela']['needs_human'] ?? false)) {
            return false;
        }

        // Cliente que já se despediu não precisa de cutucada.
        $cliente = $conversation->messages()->where('direction', 'inbound')->latest('id')->first();

        return $cliente !== null && ! $this->encerrou($cliente);
    }

    public function mensagem(WhatsAppConversation $conversation): string
    {
        $nome = Str::of((string) $conversation->profile_name)->trim()->before(' ')->toString();
        $texto = (string) $this->settings->get('followup_message');

        return str_replace('{nome}', $nome !== '' ? ' '.$nome : '', $texto);
    }

    private function enviar(WhatsAppConversation $conversation): void
    {
        $this->outbox->sendText($conversation, $this->mensagem($conversation), self::SENT_BY, null, ['followup' => true]);
    }

    /** Última mensagem da conversa que o cliente viu (o "só um minutinho" da busca não conta). */
    private function ultimaMensagem(WhatsAppConversation $conversation): ?WhatsAppMessage
    {
        return $conversation->messages()
            ->whereIn('direction', ['inbound', 'outbound'])
            ->latest('id')
            ->limit(10)
            ->get()
            ->first(fn (WhatsAppMessage $message) => ! ($message->payload['interim'] ?? false));
    }

    private function encerrou(WhatsAppMessage $message): bool
    {
        $texto = Str::lower(Str::ascii(trim((string) $message->body)));

        return $texto !== '' && mb_strlen($texto) <= 40
            && preg_match('/\b(obrigad[oa]|valeu|vlw|brigad[oa]|tchau|ate mais|era so isso|so isso|tudo certo|ok obrigad)\b/', $texto) === 1;
    }

    private function dentroDoHorario(): bool
    {
        $hora = (int) now()->format('G');

        return $hora >= self::HORA_INICIAL && $hora < self::HORA_FINAL;
    }
}
