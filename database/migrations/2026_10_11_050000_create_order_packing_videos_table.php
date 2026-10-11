<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Vídeo da embalagem gravado no KoraSync, prova do que foi embalado (2026-10-11). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_packing_videos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('status', 20)->default('processando'); // processando, pronto, erro
            $table->unsignedBigInteger('tamanho_original')->default(0);
            $table->unsignedBigInteger('tamanho')->default(0);
            $table->unsignedInteger('duracao_segundos')->nullable();
            $table->string('erro', 300)->nullable();
            $table->timestamp('expira_em')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_packing_videos');
    }
};
