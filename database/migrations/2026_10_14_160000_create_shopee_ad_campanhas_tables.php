<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estratégia Shopee (menu Publicidade, pedido do Lira em 2026-10-14):
 * campanhas de ADS por produto e o desempenho de cada uma por dia,
 * copiados da API pelo shopee:ads-campanhas para a tela abrir rápido e o
 * filtro de período funcionar. Só leitura da Shopee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_ad_campanhas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_id')->unique();
            $table->string('ad_type', 20)->nullable();
            $table->string('nome')->nullable();
            $table->string('status', 20)->nullable();
            $table->string('lance', 20)->nullable();
            $table->string('posicao', 20)->nullable();
            $table->decimal('orcamento', 10, 2)->nullable();
            $table->decimal('meta_roas', 8, 2)->nullable();
            $table->string('item_id', 30)->nullable()->index();
            $table->json('itens')->nullable();
            $table->timestamp('inicio')->nullable();
            $table->timestamp('fim')->nullable();
            $table->timestamp('sincronizado_em')->nullable();
            $table->timestamps();
        });

        Schema::create('shopee_ad_campanha_dias', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_id');
            $table->date('data');
            $table->unsignedInteger('impressoes')->default(0);
            $table->unsignedInteger('cliques')->default(0);
            $table->decimal('gasto', 10, 2)->default(0);
            $table->unsignedInteger('pedidos')->default(0);
            $table->unsignedInteger('unidades')->default(0);
            $table->decimal('venda', 12, 2)->default(0);
            $table->unsignedInteger('pedidos_diretos')->default(0);
            $table->decimal('venda_direta', 12, 2)->default(0);
            $table->timestamps();
            $table->unique(['campaign_id', 'data']);
            $table->index('data');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_ad_campanha_dias');
        Schema::dropIfExists('shopee_ad_campanhas');
    }
};
