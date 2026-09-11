<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "O canal não conhece este envio — pare de tentar sozinho."
 *
 * Ver ChannelOrderNotFoundException pro incidente (7.794 falhas em 24h,
 * 110 pedidos do TikTok de agosto em loop eterno). Marcado aqui, o envio
 * deixa de ser redisparado pelos caminhos AUTOMÁTICOS; ação humana (botão
 * de lote, reimprimir, retentativa manual) continua podendo tentar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_shipments', function (Blueprint $table) {
            $table->timestamp('unrecoverable_at')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        Schema::table('channel_shipments', function (Blueprint $table) {
            $table->dropColumn('unrecoverable_at');
        });
    }
};
