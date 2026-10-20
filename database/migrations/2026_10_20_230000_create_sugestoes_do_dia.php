<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sugestões do dia (pedido do Lira 2026-10-20): uma rotina toda madrugada
 * lê o Meta Ads, pesquisa preço de concorrente na internet, cruza com o
 * catálogo e deixa um board de ações para o dia em Indicadores.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sugestoes', function (Blueprint $table) {
            $table->id();
            $table->date('dia');
            // Mesma ação no mesmo dia não duplica (ex.: preco:baixar:123).
            $table->string('chave', 120);
            $table->string('area', 20);
            $table->string('tipo', 40);
            $table->unsignedTinyInteger('prioridade')->default(2);
            $table->string('titulo');
            $table->string('acao', 500);
            $table->text('motivo')->nullable();
            $table->json('dados')->nullable();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('meta_ad_id', 40)->nullable();
            $table->string('status', 20)->default('aberta');
            $table->timestamp('resolvida_em')->nullable();
            $table->foreignId('resolvida_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['dia', 'chave']);
            $table->index(['chave', 'status']);
        });

        Schema::create('sugestoes_rodadas', function (Blueprint $table) {
            $table->id();
            $table->date('dia');
            $table->timestamp('iniciada_em');
            $table->timestamp('terminada_em')->nullable();
            $table->unsignedInteger('total')->default(0);
            // Situação de cada fonte (Meta, Mercado Livre, Zoom): ok, erro e o motivo.
            $table->json('fontes')->nullable();
            $table->string('erro', 500)->nullable();
            $table->timestamps();

            $table->index('dia');
        });

        Schema::create('precos_concorrentes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('fonte', 20);
            $table->string('loja', 120);
            $table->string('titulo', 300);
            $table->decimal('preco', 10, 2);
            $table->string('link', 500)->nullable();
            $table->decimal('similaridade', 4, 2)->nullable();
            $table->timestamp('coletado_em');
            $table->timestamps();

            $table->index(['product_id', 'coletado_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('precos_concorrentes');
        Schema::dropIfExists('sugestoes_rodadas');
        Schema::dropIfExists('sugestoes');
    }
};
