<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Linha do tempo do pedido sem duplicidade (pedido 2026-10-09): o mesmo
 * evento repetido (webhook reentregue, consulta de envio que falha igual)
 * vira UMA linha com contador, e updated_at guarda a última vez. Antes eram
 * 1,77 milhão de linhas, 1,4 milhão só de "Webhook reentregue".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_fulfillment_events', function (Blueprint $table) {
            $table->unsignedInteger('repeticoes')->default(1)->after('context');
            $table->index(['order_id', 'step', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('order_fulfillment_events', function (Blueprint $table) {
            $table->dropIndex(['order_id', 'step', 'id']);
            $table->dropColumn('repeticoes');
        });
    }
};
