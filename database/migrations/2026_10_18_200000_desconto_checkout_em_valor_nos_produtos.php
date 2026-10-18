<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido 2026-10-18: o "Desconto Checkout" (Pix) pode ser em % ou em valor
 * fixo (R$ por unidade), como era o desconto do site. Só um dos dois fica
 * preenchido; os dois vazios = sem desconto no Pix.
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
