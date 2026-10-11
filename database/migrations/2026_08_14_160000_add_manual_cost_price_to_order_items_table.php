<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custo manual por LINHA de venda, pra tela "Lucro por Venda" do Fluxo de
 * Caixa (pedido explícito 2026-08-14). Cobre o item sem produto local
 * mapeado (order_items.product_id null — venda de um anúncio nunca trazido
 * pro catálogo, autoImportProduct() do canal falhou ou não é suportado) que
 * não tem onde gravar um cost_price (não existe Product pra editar). Item
 * COM produto mapeado continua editando Product::cost_price direto (efeito
 * permanente, vale pra qualquer venda futura do mesmo produto) — este campo
 * só entra como fallback quando isso não é possível.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('manual_cost_price', 10, 2)->nullable()->after('subtotal');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('manual_cost_price');
        });
    }
};
