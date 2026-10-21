<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perguntas MK Place para vários marketplaces (Lira, 2026-10-20): cada
 * pergunta guarda de qual canal veio. O id da pergunta só é único dentro do
 * canal (Shopee e Amazon podem repetir um número do Mercado Livre).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('ml_perguntas', 'canal')) {
            return;
        }

        Schema::table('ml_perguntas', function (Blueprint $table) {
            $table->string('canal', 24)->default('mercado_livre')->after('id')->index();
        });

        Schema::table('ml_perguntas', function (Blueprint $table) {
            $table->dropUnique(['question_id']);
            $table->unique(['canal', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::table('ml_perguntas', function (Blueprint $table) {
            $table->dropUnique(['canal', 'question_id']);
            $table->unique('question_id');
            $table->dropIndex(['canal']);
            $table->dropColumn('canal');
        });
    }
};
