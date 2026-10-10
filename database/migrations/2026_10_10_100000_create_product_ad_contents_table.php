<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conteúdo do anúncio da página do produto (pedido 2026-10-09): benefícios
 * abaixo das avaliações e a descrição em blocos (título + texto persuasivo de
 * quebra de objeção + imagem), comparativo, benefícios e dúvidas. Gerado pelo
 * Gemini a partir da descrição e das fotos; se ele falhar, por uma regra
 * própria (fonte = automatico). origem_hash diz se o produto mudou desde a
 * última geração.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_ad_contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('destaques')->nullable();
            $table->string('chamada')->nullable();
            $table->json('blocos')->nullable();
            $table->json('comparativo')->nullable();
            $table->json('beneficios')->nullable();
            $table->json('duvidas')->nullable();
            $table->string('fonte', 20);
            $table->string('origem_hash', 64)->nullable();
            $table->text('erro')->nullable();
            $table->timestamp('gerado_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_ad_contents');
    }
};
