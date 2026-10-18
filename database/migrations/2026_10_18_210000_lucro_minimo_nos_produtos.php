<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido 2026-10-18: "Lucro Mínimo" (R$) no cadastro do produto. Vazio =
 * padrão: R$ 10 para preço acima de R$ 30, R$ 8 até R$ 30. O Desconto
 * Checkout (Pix) não pode deixar o lucro da unidade abaixo dele.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('min_profit', 10, 2)->nullable()->after('cost_price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('min_profit');
        });
    }
};
