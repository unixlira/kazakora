<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Horário errado na tela de Conversas (pedido do Lira, 2026-10-19): o webhook
 * gravava o `received_at` das mensagens recebidas com a hora UTC da Meta, 3h
 * à frente de São Paulo (msg das 11:43 aparecia 14:43). O código já passa o
 * fuso; aqui as mensagens antigas voltam 3h. Só mexe em recebida cujo
 * `received_at` está 2h ou mais depois do `created_at` (a gravação real), e
 * recalcula o horário da conversa a partir das mensagens.
 */
return new class extends Migration
{
    public function up(): void
    {
        $conversas = [];

        DB::table('whatsapp_messages')
            ->where('direction', 'inbound')
            ->whereNotNull('received_at')
            ->orderBy('id')
            ->get(['id', 'conversation_id', 'created_at', 'received_at'])
            ->each(function ($m) use (&$conversas) {
                $recebida = Carbon::parse($m->received_at);
                if ($recebida->lt(Carbon::parse($m->created_at)->addHours(2))) {
                    return;
                }
                DB::table('whatsapp_messages')->where('id', $m->id)
                    ->update(['received_at' => $recebida->subHours(3)->format('Y-m-d H:i:s')]);
                $conversas[$m->conversation_id] = true;
            });

        foreach (array_keys($conversas) as $id) {
            $ultima = DB::table('whatsapp_messages')->where('conversation_id', $id)
                ->get(['received_at', 'sent_at', 'created_at'])
                ->map(fn ($m) => $m->received_at ?? $m->sent_at ?? $m->created_at)
                ->filter()
                ->max();
            if ($ultima) {
                DB::table('whatsapp_conversations')->where('id', $id)->update(['last_message_at' => $ultima]);
            }
        }
    }

    public function down(): void
    {
        // Correção de dado: não volta a hora errada.
    }
};
