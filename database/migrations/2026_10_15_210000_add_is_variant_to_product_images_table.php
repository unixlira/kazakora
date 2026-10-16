<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foto da variação (2026-10-15): a capa do produto pai costuma ser uma
 * montagem com todos os modelos, que não serve como foto do modelo dele no
 * seletor de variações. A imagem marcada aqui é a foto do modelo; sem
 * marcação, segue a principal como antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->boolean('is_variant')->default(false)->after('is_primary');
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->dropColumn('is_variant');
        });
    }
};
