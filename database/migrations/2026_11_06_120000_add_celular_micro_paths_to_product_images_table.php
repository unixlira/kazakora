<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foto de 800px do celular e miniatura de 160px da galeria (ver
 * ProductImageThumbnailer::generateCelular/generateMicro). Nullable: sem
 * elas a página usa a média de 1200px e a pequena de 320px, como antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->string('celular_path')->nullable()->after('mini_path');
            $table->string('micro_path')->nullable()->after('celular_path');
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->dropColumn(['celular_path', 'micro_path']);
        });
    }
};
