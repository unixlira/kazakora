<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido do Lira em 12/11/2026: devolução concluída sai da venda efetiva
 * (ContributionMargin::semDevolucaoConcluida). Uma das condições procura a
 * NF-e de devolução pela chave da nota de venda que ela referencia; sem
 * índice, todo filtro de venda efetiva varreria a tabela de pedidos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index('fiscal_referenced_nfe_key', 'orders_fiscal_referenced_nfe_key_index');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_fiscal_referenced_nfe_key_index');
        });
    }
};
