<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Frete embutido guardado no pedido (Lira, 2026-10-22): quanto do preço de
 * cada item era o frete somado pelo FreteEmbutido para o CEP de entrega, para
 * conferir depois contra o frete real da etiqueta sem refazer a cotação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('frete_embutido', 10, 2)->nullable()->after('subtotal');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('frete_embutido_amount', 10, 2)->nullable()->after('desconto_plataforma_amount');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('frete_embutido');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('frete_embutido_amount');
        });
    }
};
