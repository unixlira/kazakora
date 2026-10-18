<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido 2026-10-18: o "Desconto Checkout" (Pix) do produto passa a poder
 * ser em % ou em valor fixo (R$ por unidade). Nada muda nos produtos que já
 * existem: todos seguem com o % que tinham.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('checkout_discount_amount', 10, 2)->nullable()->after('checkout_discount_percentage');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('checkout_discount_amount');
        });
    }
};
