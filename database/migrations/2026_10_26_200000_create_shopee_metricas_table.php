<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Métricas da Shopee que só o Seller Center mostra (pedido do Lira em
 * 2026-10-26, tela Análise Profunda de Conta): uma linha por número, no
 * formato de ~/projects/homer/dados/shopee-tela/LEIA-ME.md, para não
 * precisar de coluna nova a cada métrica que aparecer.
 *
 * `chave` é o sha1 de escopo|item_id|model_id|periodo|data|hora|metrica
 * (MariaDB não trava duplicata com coluna nula num índice único). Número
 * de um dia (data preenchida) não leva o período na chave: o mesmo dia
 * vindo de duas coletas é o mesmo número, e a coleta mais nova vale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_metricas', function (Blueprint $table) {
            $table->id();
            $table->char('chave', 40)->unique();
            $table->timestamp('coletado_em')->nullable();
            $table->string('fonte', 20)->default('tela');
            $table->string('tela', 190)->nullable();
            $table->string('url', 500)->nullable();
            $table->date('periodo_inicio')->nullable();
            $table->date('periodo_fim')->nullable();
            $table->string('escopo', 20);
            $table->string('item_id', 30)->nullable();
            $table->string('model_id', 30)->nullable();
            $table->string('nome')->nullable();
            $table->date('data')->nullable();
            $table->unsignedTinyInteger('hora')->nullable();
            $table->string('metrica', 60);
            $table->string('rotulo', 120)->nullable();
            $table->decimal('valor', 16, 4);
            $table->string('unidade', 10)->nullable();
            $table->string('texto_original', 60)->nullable();
            $table->string('arquivo', 190)->nullable();
            $table->timestamps();

            $table->index(['escopo', 'periodo_inicio', 'periodo_fim']);
            $table->index(['escopo', 'data']);
            $table->index('item_id');
            $table->index('metrica');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_metricas');
    }
};
