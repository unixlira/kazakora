<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mais vendidos da Shopee minerados na Central do Vendedor (pedido do Lira
 * 2026-10-12): Dados › Assistente de Vendas › Produtos Mais Vendidos. A
 * Shopee mostra, por subcategoria, as unidades vendidas por categoria e os
 * 30 produtos de terceiros mais vendidos dos últimos 30 dias. Uma coleta
 * por mineração; a tela Oportunidades › Shopee lê a mais recente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_mais_vendidos_coletas', function (Blueprint $table) {
            $table->id();
            $table->date('periodo_inicio')->nullable();
            $table->date('periodo_fim')->nullable();
            $table->unsignedInteger('total_categorias')->default(0);
            $table->unsignedInteger('total_produtos')->default(0);
            $table->timestamp('coletado_em');
            $table->timestamps();
        });

        // Uma linha por subcategoria (nível 3) com as unidades vendidas na
        // Shopee inteira; posição = ordem dentro da categoria de nível 2.
        Schema::create('shopee_mais_vendidos_categorias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coleta_id')->constrained('shopee_mais_vendidos_coletas')->cascadeOnDelete();
            $table->unsignedBigInteger('l1_id');
            $table->string('l1_nome');
            $table->unsignedBigInteger('l2_id');
            $table->string('l2_nome');
            $table->unsignedBigInteger('l3_id');
            $table->string('l3_nome');
            $table->unsignedSmallInteger('posicao');
            $table->unsignedBigInteger('unidades_vendidas')->default(0);

            $table->index(['coleta_id', 'l2_id']);
        });

        // Ranking de produtos: l3_id nulo = ranking da categoria de nível 2
        // inteira; preenchido = ranking só daquela subcategoria.
        Schema::create('shopee_mais_vendidos_produtos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coleta_id')->constrained('shopee_mais_vendidos_coletas')->cascadeOnDelete();
            $table->unsignedBigInteger('l1_id');
            $table->unsignedBigInteger('l2_id');
            $table->unsignedBigInteger('l3_id')->nullable();
            $table->unsignedSmallInteger('posicao');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('shop_id');
            $table->string('nome', 300);
            $table->string('imagem', 120)->nullable();
            $table->decimal('preco', 12, 2)->nullable();
            $table->decimal('preco_min', 12, 2)->nullable();
            $table->decimal('preco_max', 12, 2)->nullable();
            $table->unsignedBigInteger('unidades_vendidas')->default(0);

            $table->index(['coleta_id', 'l2_id', 'l3_id']);
            $table->index(['coleta_id', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_mais_vendidos_produtos');
        Schema::dropIfExists('shopee_mais_vendidos_categorias');
        Schema::dropIfExists('shopee_mais_vendidos_coletas');
    }
};
