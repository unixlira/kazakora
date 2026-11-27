<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cupom de entrega em mãos (pedido do Lira em 27/11/2026): venda que o Lira
 * entrega pessoalmente. O pedido com esse cupom não cobra frete e não gera
 * etiqueta (Correios, Melhor Envio nem J3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->boolean('entrega_em_maos')->default(false)->after('so_kazakora');
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('entrega_em_maos')->default(false)->after('coupon_code');
        });
    }

    public function down(): void
    {
        Schema::table('coupons', fn (Blueprint $table) => $table->dropColumn('entrega_em_maos'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('entrega_em_maos'));
    }
};
