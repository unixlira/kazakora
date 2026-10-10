<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ofertas do dia (pedido 2026-10-10): quais produtos, em que dia, com quanto a mais. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ofertas_do_dia', function (Blueprint $table) {
            $table->id();
            $table->date('data')->index();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('posicao')->default(0);
            $table->decimal('desconto_extra', 5, 2);
            $table->decimal('preco_antes', 10, 2);
            $table->decimal('preco_oferta', 10, 2);
            $table->decimal('lucro_estimado', 10, 2);
            $table->timestamps();
            $table->unique(['data', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ofertas_do_dia');
    }
};
