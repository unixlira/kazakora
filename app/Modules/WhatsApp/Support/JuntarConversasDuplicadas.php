<?php

namespace App\Modules\WhatsApp\Support;

use App\Modules\WhatsApp\Models\WhatsAppMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Junta as conversas do mesmo número na mesma loja (uma com o nono dígito,
 * outra sem), achado real 30/10 nos pedidos #3662 e #3274. Fica a conversa em
 * que o cliente respondeu por último: o número dela é o que a Meta usa. As
 * mensagens das outras passam para ela, com tudo o que a tela mostra refeito.
 */
class JuntarConversasDuplicadas
{
    /** @return array<int, array{fica: int, sai: int[]}> */
    public function executar(): array
    {
        $grupos = DB::table('whatsapp_conversations')
            ->orderBy('id')
            ->get()
            ->groupBy(fn ($c) => $c->loja.'|'.WhatsAppPhone::chave($c->wa_id))
            ->filter(fn ($grupo) => $grupo->count() > 1);

        $resultado = [];
        foreach ($grupos as $grupo) {
            $resultado[] = DB::transaction(fn () => $this->juntar($grupo));
        }

        return $resultado;
    }

    /** @return array{fica: int, sai: int[]} */
    private function juntar($grupo): array
    {
        $fica = $grupo->sortByDesc(fn ($c) => (string) $c->last_customer_message_at)->first();
        $saem = $grupo->reject(fn ($c) => $c->id === $fica->id);
        $ids = $saem->pluck('id')->all();

        DB::table('whatsapp_messages')->whereIn('conversation_id', $ids)->update(['conversation_id' => $fica->id]);

        $ultima = DB::table('whatsapp_messages')
            ->where('conversation_id', $fica->id)
            ->whereIn('direction', ['inbound', 'outbound'])
            ->orderByRaw('COALESCE(received_at, sent_at, created_at) DESC')
            ->orderByDesc('id')
            ->first();

        $dados = [
            'profile_name' => $fica->profile_name ?: $saem->pluck('profile_name')->filter()->first(),
            'status' => $grupo->contains('status', 'open') ? 'open' : $fica->status,
            'needs_human' => $grupo->contains(fn ($c) => (bool) $c->needs_human),
            'last_message_at' => $grupo->max('last_message_at'),
            'last_customer_message_at' => $grupo->max('last_customer_message_at'),
            'last_auto_reply_at' => $grupo->max('last_auto_reply_at'),
            'unread_count' => $grupo->sum('unread_count'),
            // Em chave repetida vale o que está na conversa que fica.
            'metadata' => json_encode($saem->push($fica)->reduce(fn ($meta, $c) => array_merge($meta, json_decode((string) $c->metadata, true) ?: []), [])),
        ];

        if ($ultima) {
            $mensagem = (new WhatsAppMessage)->forceFill(['type' => $ultima->type, 'body' => $ultima->body]);
            $dados['last_message_at'] = $ultima->received_at ?? $ultima->sent_at ?? $ultima->created_at;
            $dados['last_message_preview'] = $mensagem->preview();
            $dados['last_message_direction'] = $ultima->direction;
        }

        DB::table('whatsapp_conversations')->where('id', $fica->id)->update($dados);
        DB::table('whatsapp_conversations')->whereIn('id', $ids)->delete();

        Log::info('whatsapp_conversas_juntadas', ['fica' => $fica->id, 'sai' => $ids]);

        return ['fica' => $fica->id, 'sai' => $ids];
    }
}
