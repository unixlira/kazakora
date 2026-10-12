<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Beauty Kora, página de produto igual à da KazaKora (pedido 2026-10-12):
 * o produto fica na loja da Hostinger, então o conteúdo gerado pelo Gemini
 * (mesma estrutura de product_ad_contents) é guardado aqui pelo id do
 * produto de lá.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beautykora_produto_conteudos', function (Blueprint $table) {
            $table->id();
            $table->string('produto_id', 64)->unique();
            $table->json('destaques')->nullable();
            $table->string('chamada', 255)->nullable();
            $table->json('blocos')->nullable();
            $table->json('comparativo')->nullable();
            $table->json('beneficios')->nullable();
            $table->json('duvidas')->nullable();
            $table->string('fonte', 20);
            $table->string('origem_hash', 64);
            $table->text('erro')->nullable();
            $table->timestamp('gerado_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beautykora_produto_conteudos');
    }
};
