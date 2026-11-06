<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Miniatura da foto de avaliação (tarefa 3878, 06/11): a página do produto
 * baixava a foto original (600 a 800 x 1080) para um quadrado de 60px; na
 * bike eram 7,4 MB ao rolar. Vazio = ainda sem miniatura (a tela usa a original).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_images', function (Blueprint $table) {
            $table->string('thumb_url', 1000)->nullable()->after('image_url');
        });
    }

    public function down(): void
    {
        Schema::table('review_images', function (Blueprint $table) {
            $table->dropColumn('thumb_url');
        });
    }
};
