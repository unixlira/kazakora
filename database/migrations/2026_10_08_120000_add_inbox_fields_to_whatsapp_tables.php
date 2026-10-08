<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Tela de Conversas (estilo WhatsApp): contador de não lidas, prévia da
// última mensagem na lista e quem está atendendo (Manuela ou uma pessoa).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            if (! Schema::hasColumn('whatsapp_conversations', 'unread_count')) {
                $table->unsignedInteger('unread_count')->default(0);
            }
            if (! Schema::hasColumn('whatsapp_conversations', 'ai_enabled')) {
                $table->boolean('ai_enabled')->default(true);
            }
            if (! Schema::hasColumn('whatsapp_conversations', 'last_message_preview')) {
                $table->string('last_message_preview')->nullable();
            }
            if (! Schema::hasColumn('whatsapp_conversations', 'last_message_direction')) {
                $table->string('last_message_direction', 20)->nullable();
            }
        });

        Schema::table('whatsapp_messages', function (Blueprint $table) {
            if (! Schema::hasColumn('whatsapp_messages', 'sent_by')) {
                $table->string('sent_by')->nullable();
            }
            if (! Schema::hasColumn('whatsapp_messages', 'user_id')) {
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            }
            $table->index(['conversation_id', 'updated_at']);
        });

        // Conversas que já existiam ganham a prévia da última mensagem, senão
        // não aparecem na lista (ela filtra conversa sem mensagem nenhuma).
        DB::table('whatsapp_conversations')->orderBy('id')->each(function ($conversation) {
            $last = DB::table('whatsapp_messages')
                ->where('conversation_id', $conversation->id)
                ->whereIn('direction', ['inbound', 'outbound'])
                ->orderByDesc('id')
                ->first();

            if (! $last) {
                return;
            }

            DB::table('whatsapp_conversations')->where('id', $conversation->id)->update([
                'last_message_preview' => mb_strimwidth(trim((string) $last->body) ?: 'Mensagem', 0, 250, '…'),
                'last_message_direction' => $last->direction,
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_messages', function (Blueprint $table) {
            $table->dropIndex(['conversation_id', 'updated_at']);
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn('sent_by');
        });

        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropColumn(['unread_count', 'ai_enabled', 'last_message_preview', 'last_message_direction']);
        });
    }
};
