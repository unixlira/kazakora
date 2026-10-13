<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo do Beauty Kora vindo do KazaKora (pedido 2026-10-12): em vez de
 * cadastrar na Loja Online da Hostinger, o produto é cadastrado aqui e
 * enviado em JSON (POST /api/beautykora/produtos); esta tabela diz quais
 * produtos (o pai, com as variações dele) aparecem no site.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beautykora_produtos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('products')->cascadeOnDelete();
            $table->string('titulo', 255)->nullable();
            $table->integer('ordem')->default(0);
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('beautykora_produtos');
    }
};
