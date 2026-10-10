<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desconto no Pix da loja (pedido 2026-10-09): guardado à parte do desconto
 * de cupom pra ficar claro no pedido quanto saiu por pagar no Pix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('pix_discount_amount', 10, 2)->default(0)->after('discount_amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('pix_discount_amount');
        });
    }
};
