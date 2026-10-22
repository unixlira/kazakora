<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Livros digitais ganhos em cada pedido (pedido 2026-10-21): gravados na hora
 * da compra, com a regra que a página do produto mostrava. O cliente baixa
 * pelo link do e-mail e da página do pedido mesmo que a promoção mude ou
 * acabe depois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pedido_livros_digitais', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('livro_digital_id')->constrained('livros_digitais')->cascadeOnDelete();
            $table->unsignedInteger('downloads')->default(0);
            $table->timestamp('ultimo_download_em')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'livro_digital_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pedido_livros_digitais');
    }
};
