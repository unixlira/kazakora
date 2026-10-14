<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Justificativa de envio Flex entregue sem bipar no KoraFlex (pedido do
 * usuário em 2026-10-13): o Mercado Livre diz que entregou, mas a caixa
 * nunca passou pelo app. Alguém registra o porquê — quem, quando e o
 * motivo ficam gravados, do mesmo jeito que a conferência da devolução.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_shipments', function (Blueprint $table) {
            $table->string('koraflex_justification', 30)->nullable();
            $table->text('koraflex_justification_note')->nullable();
            $table->timestamp('koraflex_justified_at')->nullable();
            $table->unsignedBigInteger('koraflex_justified_by')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('channel_shipments', function (Blueprint $table) {
            $table->dropColumn([
                'koraflex_justification', 'koraflex_justification_note',
                'koraflex_justified_at', 'koraflex_justified_by',
            ]);
        });
    }
};
