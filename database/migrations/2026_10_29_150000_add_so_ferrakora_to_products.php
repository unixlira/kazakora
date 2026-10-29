<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Produto só do site FerraKora (pedido do Lira, 2026-10-29: os produtos do
 * FerraKora que vieram do homolog ficam "só no FerraKora"). Continua ativo
 * para o FerraKora, mas some da loja kazakora.com (vitrine, busca, página,
 * carrinho, sitemap, ofertas do dia e Manuela).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('so_ferrakora')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('so_ferrakora');
        });
    }
};
