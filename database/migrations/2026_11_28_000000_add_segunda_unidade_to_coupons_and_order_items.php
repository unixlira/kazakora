<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Compre 1, o 2º sai por R$ X" (pedido do Lira em 27/11/2026): cupom do dia
 * (LEVE2 + DDMM) que põe a 2ª unidade de cada par pelo preço da promoção
 * (LeveDois). O pedido guarda, por item, quantas unidades saíram pelo preço
 * da 2ª, o preço normal da unidade, o preço da promoção e o desconto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->boolean('segunda_unidade')->default(false)->after('entrega_em_maos');
        });
        Schema::table('order_items', function (Blueprint $table) {
            $table->unsignedInteger('leve2_unidades')->nullable()->after('frete_embutido');
            $table->decimal('leve2_preco_normal', 10, 2)->nullable()->after('leve2_unidades');
            $table->decimal('leve2_preco', 10, 2)->nullable()->after('leve2_preco_normal');
            $table->decimal('leve2_desconto', 10, 2)->nullable()->after('leve2_preco');
        });
    }

    public function down(): void
    {
        Schema::table('coupons', fn (Blueprint $table) => $table->dropColumn('segunda_unidade'));
        Schema::table('order_items', fn (Blueprint $table) => $table->dropColumn(['leve2_unidades', 'leve2_preco_normal', 'leve2_preco', 'leve2_desconto']));
    }
};
