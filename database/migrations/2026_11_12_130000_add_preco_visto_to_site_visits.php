<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preço que a página do produto mostrou ao visitante quando abriu (pedido
 * do Lira, 2026-11-12: coluna Valor em Visitas KazaKora e Visitas
 * FerraKora). Gravado na hora da visita (PrecoVisto), porque o preço muda
 * com oferta e com a região do visitante. Nulo nas páginas que não são de
 * produto e nas visitas anteriores.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_visits', function (Blueprint $table) {
            $table->decimal('preco_visto', 10, 2)->nullable()->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('site_visits', function (Blueprint $table) {
            $table->dropColumn('preco_visto');
        });
    }
};
