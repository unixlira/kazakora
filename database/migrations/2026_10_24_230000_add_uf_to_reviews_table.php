<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UF do cliente ao lado do nome no carrossel do checkout (pedido 2026-10-24).
 * Só a real: estado de entrega do pedido que gerou a avaliação (order_sn da
 * Shopee ou pedido do site). Avaliação sem pedido nosso fica sem UF.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->string('uf', 2)->nullable()->after('reviewer_name');
        });
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn('uf');
        });
    }
};
