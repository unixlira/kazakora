<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Veja na prática" (pedido 2026-10-20, Lanterna LED): cartões do produto em
 * uso no fim da descrição, depois da chamada. Cada item: titulo, texto e
 * midia (índice da foto do produto ou caminho em storage: gif, webp ou mp4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_ad_contents', function (Blueprint $table) {
            $table->json('pratica')->nullable()->after('duvidas');
        });
    }

    public function down(): void
    {
        Schema::table('product_ad_contents', function (Blueprint $table) {
            $table->dropColumn('pratica');
        });
    }
};
