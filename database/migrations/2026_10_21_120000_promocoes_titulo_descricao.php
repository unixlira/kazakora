<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vender › Promoções (pedido do Lira 2026-10-21): título e descrição de cada
 * promoção, mostrados na página pública /promocoes/{slug}. A descrição é texto
 * puro com quebras de linha (linha em branco separa parágrafos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promocoes', function (Blueprint $table) {
            $table->string('titulo')->nullable()->after('nome');
            $table->text('descricao')->nullable()->after('titulo');
        });

        DB::table('promocoes')->where('slug', 'airfryer')->update(['titulo' => 'Compre, concorra no air fryer']);
    }

    public function down(): void
    {
        Schema::table('promocoes', function (Blueprint $table) {
            $table->dropColumn(['titulo', 'descricao']);
        });
    }
};
