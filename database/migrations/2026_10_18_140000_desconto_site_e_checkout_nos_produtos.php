<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido 2026-10-18: o produto passa a ter "Desconto Site (%)" (o antigo
 * discount_percentage) e "Desconto Checkout (%)" (desconto no Pix, por
 * produto, no lugar dos 5% fixos no código).
 *
 * Nada muda de preço para o cliente: o desconto em R$ vira o % equivalente
 * (4 casas, o preço final bate no centavo) e todo produto que já existe
 * recebe 5% no Checkout, o que o Pix dava até hoje.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('discount_percentage', 8, 4)->nullable()->change();
            $table->decimal('checkout_discount_percentage', 5, 2)->nullable()->after('discount_amount');
        });

        DB::table('products')->update(['checkout_discount_percentage' => 5]);

        DB::table('products')
            ->where('discount_amount', '>', 0)
            ->where('price', '>', 0)
            ->where(fn ($query) => $query->whereNull('discount_percentage')->orWhere('discount_percentage', 0))
            ->orderBy('id')
            ->get(['id', 'price', 'discount_amount'])
            ->each(fn ($produto) => DB::table('products')->where('id', $produto->id)->update([
                'discount_percentage' => round(min(100, (float) $produto->discount_amount * 100 / (float) $produto->price), 4),
                'discount_amount' => null,
            ]));
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('checkout_discount_percentage');
        });
    }
};
