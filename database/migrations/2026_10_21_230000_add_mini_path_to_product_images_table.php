<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foto pequena das miniaturas (ver ProductImageThumbnailer::generateMini).
 * Nullable: sem ela a miniatura usa a foto de 512px, como antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->string('mini_path')->nullable()->after('medium_path');
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->dropColumn('mini_path');
        });
    }
};
