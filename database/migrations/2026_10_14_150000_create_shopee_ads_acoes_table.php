<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estratégia Shopee, botão Ação (tarefa 569, 2026-10-14): cada vez que o
 * Lira manda o Cauê pausar, retomar ou mudar a meta de ROAS de uma campanha
 * de ADS por produto, fica aqui o antes, o depois, quem clicou, a resposta
 * da Shopee e a conferência (campanha relida depois de mandar). O "antes"
 * é o que o Desfazer manda de volta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_ads_acoes', function (Blueprint $table) {
            $table->id();
            $table->string('item_id', 32)->index();
            $table->string('campaign_id', 32)->index();
            $table->string('nome_produto')->nullable();
            // pausar, retomar, meta_roas
            $table->string('acao', 20);
            $table->json('antes');
            $table->json('depois');
            // simulada (escrita desligada), conferida, nao_conferida, erro
            $table->string('status', 20);
            $table->boolean('simulacao')->default(false);
            $table->json('resposta')->nullable();
            $table->text('erro')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('conferida_em')->nullable();
            $table->timestamp('desfeita_em')->nullable();
            // Ação que desfez outra aponta para ela.
            $table->foreignId('desfaz_id')->nullable()->constrained('shopee_ads_acoes')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_ads_acoes');
    }
};
