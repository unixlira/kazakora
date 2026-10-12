<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Radar da Shopee (pedido do Lira 2026-10-12): ranking "Produtos em alta"
 * da Central do Vendedor (seller.shopee.com.br/datacenter/selling/top-products),
 * lido no navegador logado do Cora Box e importado por
 * `shopee:radar-importar`. Uma coleta por importação; a tela mostra a
 * mais recente e as anteriores ficam como histórico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_radar_coletas', function (Blueprint $table) {
            $table->id();
            $table->timestamp('coletado_em');
            $table->unsignedInteger('total_categorias')->default(0);
            $table->unsignedInteger('total_produtos')->default(0);
            $table->string('origem', 60)->default('central-do-vendedor');
            $table->timestamps();

            $table->index('coletado_em');
        });

        Schema::create('shopee_radar_categorias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coleta_id')->constrained('shopee_radar_coletas')->cascadeOnDelete();
            $table->unsignedBigInteger('l1_id');
            $table->string('l1_nome');
            $table->unsignedBigInteger('l2_id');
            $table->string('l2_nome');
            $table->unsignedBigInteger('l3_id');
            $table->string('l3_nome');
            $table->unsignedSmallInteger('posicao');
            $table->unsignedBigInteger('unidades_vendidas')->default(0);

            $table->unique(['coleta_id', 'l3_id']);
        });

        Schema::create('shopee_radar_produtos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coleta_id')->constrained('shopee_radar_coletas')->cascadeOnDelete();
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('shop_id');
            $table->unsignedBigInteger('l1_id');
            $table->unsignedBigInteger('l2_id');
            $table->unsignedBigInteger('l3_id');
            $table->unsignedSmallInteger('posicao');
            $table->string('titulo', 500);
            $table->string('imagem', 120)->nullable();
            $table->decimal('preco', 12, 2)->nullable();
            $table->decimal('preco_min', 12, 2)->nullable();
            $table->decimal('preco_max', 12, 2)->nullable();
            $table->unsignedBigInteger('unidades_vendidas')->default(0);

            $table->unique(['coleta_id', 'l3_id', 'item_id']);
            $table->index(['coleta_id', 'unidades_vendidas']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_radar_produtos');
        Schema::dropIfExists('shopee_radar_categorias');
        Schema::dropIfExists('shopee_radar_coletas');
    }
};
