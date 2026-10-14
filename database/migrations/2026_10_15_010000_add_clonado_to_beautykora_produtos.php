<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Produto clonado no Beauty Kora (pedido do Lira 2026-10-15): o anúncio
 * importado do KazaKora sai no site com 5% a mais sobre o preço original
 * (BeautyKoraProduto::ACRESCIMO_CLONADO). Todo produto do catálogo vindo do
 * KazaKora é clone do anúncio de lá; "clonado": false no JSON tira o acréscimo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('beautykora_produtos', function (Blueprint $table) {
            $table->boolean('clonado')->default(true)->after('ativo');
        });
    }

    public function down(): void
    {
        Schema::table('beautykora_produtos', function (Blueprint $table) {
            $table->dropColumn('clonado');
        });
    }
};
