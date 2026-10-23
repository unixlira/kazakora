<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modal "Avalie sua compra" depois do login: quantas vezes ele já abriu para
 * cada produto de cada cliente, para parar de aparecer depois do limite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avaliacao_lembretes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('aparicoes')->default(0);
            $table->timestamp('ultima_aparicao_em')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avaliacao_lembretes');
    }
};
