<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nota de devolução automática (pedido 2026-10-11): o caso de devolução
 * guarda a NF-e de entrada preparada para ele e em que pé ela está.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_returns', function (Blueprint $table) {
            $table->foreignId('devolucao_order_id')->nullable()->after('order_id')->constrained('orders')->nullOnDelete();
            // preparada, emitida, descartada, pelo_canal, sem_nota, pendencia
            $table->string('devolucao_fiscal_status', 20)->nullable()->after('devolucao_order_id');
            $table->string('devolucao_fiscal_obs', 500)->nullable()->after('devolucao_fiscal_status');
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('devolucao_order_id');
            $table->dropColumn(['devolucao_fiscal_status', 'devolucao_fiscal_obs']);
        });
    }
};
