<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidências (fotos e vídeos) de uma devolução — pedido do usuário
 * 2026-10-07: o que chegou, como chegou, pra contestar na plataforma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_return_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_return_id')->constrained()->cascadeOnDelete();
            $table->string('tipo', 10); // foto | video
            $table->string('path');
            $table->string('nome_original')->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('tamanho');
            $table->unsignedSmallInteger('duracao_segundos')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_return_evidences');
    }
};
