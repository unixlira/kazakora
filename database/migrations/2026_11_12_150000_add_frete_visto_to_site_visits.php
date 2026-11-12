<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Frete que estava dentro do preço mostrado na página do produto (pedido do
 * Lira, 2026-11-12: no olho das Visitas, o preço total e o frete incluído).
 * Gravado junto com preco_visto, porque o frete embutido muda com a região
 * do visitante. Nulo fora da página de produto e nas visitas anteriores.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_visits', function (Blueprint $table) {
            $table->decimal('frete_visto', 10, 2)->nullable()->after('preco_visto');
        });
    }

    public function down(): void
    {
        Schema::table('site_visits', function (Blueprint $table) {
            $table->dropColumn('frete_visto');
        });
    }
};
