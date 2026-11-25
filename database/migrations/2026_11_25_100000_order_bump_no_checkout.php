<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order bump no checkout da KazaKora (pedido do Lira, 2026-11-24: "criar order
 * bump, ver se aumenta o ticket"). Ver App\Modules\Checkout\Support\OrderBump.
 *
 * - products.order_bump_product_id / order_bump_desconto_pct: oferta escolhida
 *   no cadastro do produto (quem tem este produto no carrinho vê aquela oferta).
 * - order_items.origem: "order_bump" no item que entrou pela caixa da oferta,
 *   para medir; nulo nos itens normais.
 * - order_bump_eventos: exibição, marcou e desmarcou da caixa (sem dado pessoal,
 *   só o hash da sessão), base da tela Admin > Order bump.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('order_bump_product_id')->nullable()->after('cta_compra');
            $table->decimal('order_bump_desconto_pct', 5, 2)->nullable()->after('order_bump_product_id');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->string('origem', 20)->nullable()->after('subtotal');
            $table->index('origem');
        });

        Schema::create('order_bump_eventos', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 20);
            $table->unsignedBigInteger('product_id');
            $table->string('sessao', 64);
            $table->decimal('preco', 10, 2)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['tipo', 'created_at']);
            $table->index(['sessao', 'product_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_bump_eventos');

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropIndex(['origem']);
            $table->dropColumn('origem');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['order_bump_product_id', 'order_bump_desconto_pct']);
        });
    }
};
