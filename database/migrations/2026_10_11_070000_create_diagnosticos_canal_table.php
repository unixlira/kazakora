<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Último diagnóstico de cada canal (Cauê/Shopee e Diego/Mercado Livre),
 * gerado pelo marketplace:diagnostico a cada 3 h e lido pelo dashboard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diagnosticos_canal', function (Blueprint $table) {
            $table->id();
            $table->string('canal', 30)->unique();
            $table->json('dados');
            $table->timestamp('gerado_em');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnosticos_canal');
    }
};
