<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Imagens do anúncio geradas pelo Gemini na edição do produto (pedido
 * 2026-10-19): de 3 a 5 por lote, na sequência do Lira (desejo → quebra de
 * objeção → CTA). Ficam aqui como rascunho até alguém aprovar; aprovada, a
 * imagem vira uma foto do produto (product_image_id) no fim da galeria.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_ia_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('lote', 26);
            $table->unsignedTinyInteger('ordem');
            $table->string('etapa', 10);
            $table->string('titulo', 160);
            $table->text('cena');
            $table->string('status', 12)->default('pendente');
            $table->string('path')->nullable();
            $table->string('modelo', 60)->nullable();
            $table->text('erro')->nullable();
            $table->foreignId('product_image_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('aprovada_em')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'lote']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_ia_images');
    }
};
