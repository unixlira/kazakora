<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foto média da página de produto (ver ProductImageThumbnailer::generateMedium).
 * Nullable: sem ela a página usa a original, como antes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->string('medium_path')->nullable()->after('thumb_path');
        });
    }

    public function down(): void
    {
        Schema::table('product_images', function (Blueprint $table) {
            $table->dropColumn('medium_path');
        });
    }
};
