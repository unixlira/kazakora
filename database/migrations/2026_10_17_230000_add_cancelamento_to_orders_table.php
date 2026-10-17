<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Botão "Cancelar pedido" do admin (2026-10-17): guarda quando, quem e por
 * que o pedido foi cancelado pela tela. Cancelamento que chega do canal
 * (webhook/varredura) continua sem preencher: ele já tem a data no envio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('cancelado_em')->nullable();
            $table->foreignId('cancelado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motivo_cancelamento', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cancelado_por');
            $table->dropColumn(['cancelado_em', 'motivo_cancelamento']);
        });
    }
};
