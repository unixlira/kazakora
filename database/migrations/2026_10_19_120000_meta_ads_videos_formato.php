<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta ADS (2026-10-18): tamanho do vídeo (medido no upload) e a versão 9:16
 * gerada para vídeo horizontal antes de subir para a Meta (FormatoDeVideo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_ads_videos', function (Blueprint $table) {
            $table->unsignedSmallInteger('largura')->nullable()->after('duracao_segundos');
            $table->unsignedSmallInteger('altura')->nullable()->after('largura');
            $table->string('path_vertical')->nullable()->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('meta_ads_videos', function (Blueprint $table) {
            $table->dropColumn(['largura', 'altura', 'path_vertical']);
        });
    }
};
