<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vários números do WhatsApp (2026-10-22): a conversa passa a ser da loja
 * (kazakora/ferrakora). O mesmo cliente nos dois números vira duas
 * conversas, então a unicidade sai de wa_id e vai para (wa_id, loja).
 * As conversas que já existem ficam todas na KazaKora (padrão).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('whatsapp_conversations', 'loja')) {
            Schema::table('whatsapp_conversations', function (Blueprint $table) {
                $table->string('loja', 20)->default('kazakora')->after('wa_id');
            });
        }

        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->unique(['wa_id', 'loja'], 'whatsapp_conversations_wa_id_loja_unique');
        });

        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            // A busca por wa_id usa o índice composto (wa_id vem primeiro).
            $table->dropUnique('whatsapp_conversations_wa_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->unique('wa_id', 'whatsapp_conversations_wa_id_unique');
        });

        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropUnique('whatsapp_conversations_wa_id_loja_unique');
            $table->dropColumn('loja');
        });
    }
};
