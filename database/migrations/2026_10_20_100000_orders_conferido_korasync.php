<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Botão "Conferido" do KoraSync (pedido do Lira, 2026-10-19): nas abas
 * Aguardando separação e Cancelados o operador marca o pedido como visto e
 * ele sai da fila, sem apagar nada e sem mexer em status, packed_at ou
 * etiqueta. O pedido continua achável na busca do KoraSync (histórico) e no
 * admin. Só colunas novas e anuláveis: nenhum dado existente muda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('conferido_em')->nullable()->index();
            $table->string('conferido_por', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['conferido_em']);
            $table->dropColumn(['conferido_em', 'conferido_por']);
        });
    }
};
