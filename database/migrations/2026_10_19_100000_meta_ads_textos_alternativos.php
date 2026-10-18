<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta ADS (2026-10-18): a campanha da Árvore de Natal que o Lira montou no
 * Gerenciador tem 5 textos, 5 títulos e 5 descrições por anúncio (a Meta
 * testa as combinações). textos_alternativos guarda as opções além do
 * texto, título e descrição principais: {textos: [], titulos: [], descricoes: []}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_ads_campanhas', function (Blueprint $table) {
            $table->json('textos_alternativos')->nullable()->after('descricao');
        });
    }

    public function down(): void
    {
        Schema::table('meta_ads_campanhas', function (Blueprint $table) {
            $table->dropColumn('textos_alternativos');
        });
    }
};
