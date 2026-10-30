<?php

use App\Modules\WhatsApp\Support\JuntarConversasDuplicadas;
use App\Modules\WhatsApp\Support\WhatsAppPhone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Uma conversa só por número em cada loja (2026-10-30, pedidos #3662 e #3274):
 * o disparo gravava o número com o nono dígito, a resposta da Meta vinha sem e
 * abria outra conversa. Junta as que já existem e deixa o banco recusar a
 * duplicata, inclusive com dois processos gravando ao mesmo tempo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('whatsapp_conversations', 'numero_chave')) {
            Schema::table('whatsapp_conversations', function (Blueprint $table) {
                $table->string('numero_chave')->nullable()->after('wa_id');
            });
        }

        app(JuntarConversasDuplicadas::class)->executar();

        DB::table('whatsapp_conversations')->orderBy('id')->each(function ($conversa) {
            DB::table('whatsapp_conversations')->where('id', $conversa->id)
                ->update(['numero_chave' => WhatsAppPhone::chave($conversa->wa_id)]);
        });

        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->unique(['numero_chave', 'loja'], 'whatsapp_conversations_numero_chave_loja_unique');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_conversations', function (Blueprint $table) {
            $table->dropUnique('whatsapp_conversations_numero_chave_loja_unique');
            $table->dropColumn('numero_chave');
        });
    }
};
