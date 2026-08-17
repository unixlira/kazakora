<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido explícito do usuário 2026-08-17: variação de produto precisa ter
 * cadastro de verdade (como Shopee/Mercado Livre) — hoje cada variação
 * (ex.: Ring Light 8" vs 10") é um Product totalmente desconectado, ligado
 * só por convenção de nome. Auto-referência em vez de tabela separada
 * (product_variations): CartManager, OrderItem, unitPriceForQuantity()/
 * quantityDiscounts e o lock de estoque (StockManager::adjust()) são todos
 * ligados via FK direta a products.id — uma tabela separada duplicaria essa
 * plumbing inteira. Nula por padrão: produto sem variação continua
 * exatamente como hoje, sem migração de dado nenhuma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('parent_product_id')
                ->nullable()
                ->after('category_id')
                ->constrained('products')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_product_id');
        });
    }
};
