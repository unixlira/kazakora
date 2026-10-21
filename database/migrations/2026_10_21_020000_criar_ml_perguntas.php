<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perguntas dos anúncios do Mercado Livre (tópico "questions" do webhook).
 * Guarda a pergunta e o rascunho da Manuela; nada vai ao cliente sem OK.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ml_perguntas')) {
            return;
        }

        Schema::create('ml_perguntas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('question_id')->unique();
            $table->string('item_id', 32)->index();
            $table->unsignedBigInteger('comprador_id')->nullable();
            $table->string('status', 24)->index();
            $table->text('pergunta')->nullable();
            $table->text('resposta_rascunho')->nullable();
            $table->boolean('precisa_humano')->default(false);
            $table->text('resposta_enviada')->nullable();
            $table->timestamp('perguntada_em')->nullable();
            $table->timestamp('rascunho_em')->nullable();
            $table->timestamp('respondida_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ml_perguntas');
    }
};
