<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Oferta Relâmpago diária da Shopee (pedido 2026-10-17): a lista de
 * produtos do Lira e o registro de cada dia programado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopee_relampago_itens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('item_id');
            // Só para anúncio com variação; sem variação fica nulo.
            $table->unsignedBigInteger('model_id')->nullable();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('nome');
            // preco = preço fixo da Relâmpago; desconto_reais / desconto_pct =
            // tira do preço atual do anúncio.
            $table->string('modo', 20)->default('preco');
            $table->decimal('valor', 10, 2);
            // Unidades na Relâmpago; nulo = % do estoque do anúncio (estoque_pct).
            $table->unsignedInteger('estoque')->nullable();
            // 0 = sem limite por comprador.
            $table->unsignedInteger('limite_por_comprador')->default(0);
            // Custo e lucro mínimo próprios (nulo = custo do cadastro e regra geral).
            $table->decimal('custo', 10, 2)->nullable();
            $table->decimal('lucro_minimo', 10, 2)->nullable();
            $table->boolean('ativo')->default(true);
            $table->string('observacao')->nullable();
            $table->timestamps();

            $table->unique(['item_id', 'model_id']);
        });

        Schema::create('shopee_relampago_execucoes', function (Blueprint $table) {
            $table->id();
            $table->date('dia');
            $table->unsignedBigInteger('timeslot_id')->nullable();
            $table->unsignedBigInteger('flash_sale_id')->nullable();
            // ok, parcial, falha
            $table->string('status', 20);
            $table->unsignedInteger('adicionados')->default(0);
            $table->unsignedInteger('recusados')->default(0);
            $table->unsignedInteger('ativos_na_shopee')->default(0);
            $table->json('detalhe')->nullable();
            $table->timestamps();

            $table->index('dia');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopee_relampago_execucoes');
        Schema::dropIfExists('shopee_relampago_itens');
    }
};
