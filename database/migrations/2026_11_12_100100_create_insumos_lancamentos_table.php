<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tela Insumos (pedido do Lira em 12/11/2026: "cria uma tela de insumos que
 * lá vou colocar valor de custo de materiais do escritório e do galpão para
 * entrar no custo"). Cada lançamento sai do lucro pela data dele
 * (ContributionMargin::insumosLancados).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insumos_lancamentos', function (Blueprint $table) {
            $table->id();
            $table->date('data')->index();
            $table->string('descricao', 200);
            $table->string('categoria', 20); // escritorio, galpao, embalagem, outro
            $table->decimal('valor', 10, 2);
            $table->string('observacao', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insumos_lancamentos');
    }
};
