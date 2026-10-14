<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Checkout do Beauty Kora pelo Mercado Pago da KazaKora (2026-10-14): o item
 * de "Meus pedidos" aponta para o pedido da KazaKora, de onde vem a situação.
 * Nulo = pedido antigo, pago na Hostinger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beautykora_pedidos', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('cliente_id')->constrained('orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('beautykora_pedidos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
        });
    }
};
