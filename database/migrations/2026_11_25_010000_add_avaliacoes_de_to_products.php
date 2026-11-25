<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avaliações de outros produtos na página (pedido do Lira 24/11/2026, kits de brinquedo):
 * lista [{"id": 213, "rotulo": "Livro Falante"}, ...]. A página junta as avaliações reais
 * desses produtos (e das variações deles) às do próprio produto, com a etiqueta de cada um.
 * Nada é copiado: em produção é só gravar os ids de produção.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'avaliacoes_de')) {
            return;
        }
        Schema::table('products', function (Blueprint $table) {
            $table->json('avaliacoes_de')->nullable()->after('layout_pagina');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'avaliacoes_de')) {
            Schema::table('products', fn (Blueprint $table) => $table->dropColumn('avaliacoes_de'));
        }
    }
};
