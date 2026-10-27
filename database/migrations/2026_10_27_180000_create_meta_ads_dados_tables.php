<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Metadados das campanhas da Meta gravados no nosso banco (pedido do Lira
 * em 2026-10-27): a conta inteira (inclusive o que foi criado direto no
 * Gerenciador e o que já foi excluído), Campanha > Conjunto > Anúncio, e a
 * performance de cada anúncio por dia. Quem enche é o meta-ads:gravar-dados.
 * Os ids são os da Meta (texto): o mesmo objeto nunca entra duas vezes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_ads_dados_campanhas', function (Blueprint $table) {
            $table->id();
            $table->string('meta_id', 40)->unique();
            $table->string('nome');
            $table->string('status', 30)->nullable();
            $table->string('objetivo', 40)->nullable();
            $table->decimal('orcamento_diario', 10, 2)->nullable();
            $table->decimal('orcamento_total', 10, 2)->nullable();
            $table->date('inicio')->nullable();
            $table->date('fim')->nullable();
            $table->timestamp('criada_na_meta')->nullable();
            $table->timestamp('sincronizado_em')->nullable();
            $table->timestamps();
        });

        Schema::create('meta_ads_dados_conjuntos', function (Blueprint $table) {
            $table->id();
            $table->string('meta_id', 40)->unique();
            $table->string('campanha_meta_id', 40)->index();
            $table->string('nome');
            $table->string('status', 30)->nullable();
            $table->decimal('orcamento_diario', 10, 2)->nullable();
            $table->decimal('orcamento_total', 10, 2)->nullable();
            $table->timestamp('criado_na_meta')->nullable();
            $table->timestamp('sincronizado_em')->nullable();
            $table->timestamps();
        });

        Schema::create('meta_ads_dados_anuncios', function (Blueprint $table) {
            $table->id();
            $table->string('meta_id', 40)->unique();
            $table->string('conjunto_meta_id', 40)->index();
            $table->string('campanha_meta_id', 40)->index();
            $table->string('nome');
            $table->string('status', 30)->nullable();
            $table->text('miniatura')->nullable();
            $table->timestamp('criado_na_meta')->nullable();
            $table->timestamp('sincronizado_em')->nullable();
            $table->timestamps();
        });

        // Um registro por anúncio por dia (fuso da conta). Campanha e conjunto
        // somam os anúncios; alcance e frequência ficam de fora porque não se
        // somam (a mesma pessoa vê vários anúncios em vários dias).
        Schema::create('meta_ads_dados_desempenho', function (Blueprint $table) {
            $table->id();
            $table->string('anuncio_meta_id', 40);
            $table->string('conjunto_meta_id', 40)->index();
            $table->string('campanha_meta_id', 40)->index();
            $table->date('data');
            $table->decimal('gasto', 12, 2)->default(0);
            $table->unsignedInteger('impressoes')->default(0);
            $table->unsignedInteger('cliques')->default(0);
            $table->unsignedInteger('cliques_link')->default(0);
            $table->unsignedInteger('visualizacoes_pagina')->default(0);
            $table->unsignedInteger('carrinho')->default(0);
            $table->unsignedInteger('checkout')->default(0);
            $table->unsignedInteger('compras')->default(0);
            $table->decimal('vendas', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['anuncio_meta_id', 'data']);
            $table->index('data');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_ads_dados_desempenho');
        Schema::dropIfExists('meta_ads_dados_anuncios');
        Schema::dropIfExists('meta_ads_dados_conjuntos');
        Schema::dropIfExists('meta_ads_dados_campanhas');
    }
};
