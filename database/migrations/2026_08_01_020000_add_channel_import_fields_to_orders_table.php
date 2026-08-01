<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // MySQL trata NULL como distinto num índice único — não impede
            // múltiplos pedidos da loja (external_order_id sempre null) de
            // coexistirem, só impede reimportar o mesmo pedido de um canal
            // externo duas vezes (reentrega de webhook).
            $table->unique(['origin', 'external_order_id']);
            // Trava simples pra devolução de estoque de um pedido não
            // pago/cancelado/expirado só acontecer uma vez.
            $table->timestamp('stock_restored_at')->nullable()->after('disputed_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['origin', 'external_order_id']);
            $table->dropColumn('stock_restored_at');
        });
    }
};
