<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido 2026-10-18: o "Desconto Checkout" (Pix) do produto pode ser
 * Percentual ou Valor fixo (R$ por unidade). Os que já existem continuam
 * Percentual, sem mudar o preço de ninguém.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('checkout_discount_type', 10)->default('percentage')->after('checkout_discount_percentage');
            $table->decimal('checkout_discount_amount', 10, 2)->nullable()->after('checkout_discount_type');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['checkout_discount_type', 'checkout_discount_amount']);
        });
    }
};
