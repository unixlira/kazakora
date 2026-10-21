<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing › Validação de criativos (2026-10-21): vídeos de anúncio (Meta,
 * TikTok, Google...) enviados pela equipe e guardados no servidor até o Lira
 * aprovar, pedir ajuste ou reprovar. Lixeira em vez de apagar (SoftDeletes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('criativos_validacao', function (Blueprint $table) {
            $table->id();
            $table->string('titulo', 150);
            $table->string('plataforma', 30);
            $table->foreignId('produto_id')->nullable()->constrained('products')->nullOnDelete();
            $table->text('observacao')->nullable();
            $table->string('nome_original', 255);
            $table->string('path');
            $table->unsignedBigInteger('tamanho');
            $table->decimal('duracao_segundos', 8, 2)->nullable();
            $table->unsignedSmallInteger('largura')->nullable();
            $table->unsignedSmallInteger('altura')->nullable();
            $table->string('status', 20)->default('aguardando')->index();
            $table->text('comentario_avaliacao')->nullable();
            $table->foreignId('enviado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('avaliado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('avaliado_em')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('criativos_validacao');
    }
};
