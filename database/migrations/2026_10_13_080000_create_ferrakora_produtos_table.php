<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo do FerraKora vindo do KazaKora (pedido 2026-10-13), igual ao do
 * Beauty Kora: o JSON enviado em POST /api/ferrakora/produtos diz quais
 * produtos (o pai, com as variações dele) aparecem no site e em qual
 * categoria do menu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ferrakora_produtos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();
            $table->string('titulo', 255)->nullable();
            $table->string('categoria', 100)->nullable();
            $table->integer('ordem')->default(0);
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ferrakora_produtos');
    }
};
