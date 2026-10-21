<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vender › Promoções na página do produto (pedido do Lira 2026-10-21): cada
 * promoção (Airfryer = quadro do sorteio, PDFs = quadro dos livros) tem a
 * chave "mostrar na página do produto", a imagem do quadro e em quais
 * produtos aparece. `promocao_produto` liga a promoção a cada produto (pai):
 * com "todos os produtos", a linha desligada tira só daquele produto; sem,
 * só aparece nos produtos com a linha ligada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promocoes', function (Blueprint $table) {
            // Separada de `ativa` (página pública /promocoes/{slug}): os quadros
            // já estão no ar e não podem sumir com esta migration.
            $table->boolean('na_pagina_produto')->default(true)->after('ativa');
            $table->boolean('todos_produtos')->default(true)->after('na_pagina_produto');
            // null = imagem de Entregáveis; "images/..." = do repositório; senão upload no disco public.
            $table->string('imagem')->nullable()->after('todos_produtos');
        });

        Schema::create('promocao_produto', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promocao_id')->constrained('promocoes')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->unique(['promocao_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promocao_produto');

        Schema::table('promocoes', function (Blueprint $table) {
            $table->dropColumn(['na_pagina_produto', 'todos_produtos', 'imagem']);
        });
    }
};
