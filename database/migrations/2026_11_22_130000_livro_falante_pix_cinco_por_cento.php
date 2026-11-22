<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Black Friday do Livro Falante (pedido do Lira 22/11/2026): "mais 3% no Pix"
 * nas mensagens de carrinho. O Pix do produto era 2% (Desconto Checkout do
 * cadastro); passa para 5% (2% + 3%), no produto e nas variações. Depois de
 * 30/11, voltar para 2% no cadastro (Admin › Produtos › Desconto Checkout).
 */
return new class extends Migration
{
    private const SLUG = 'livro-falante-infantil-bilingue-portugues-e-ingles-com-som-11-temas-e-300-palavras';

    public function up(): void
    {
        $id = DB::table('products')->where('slug', self::SLUG)->value('id');
        if ($id) {
            DB::table('products')->where('id', $id)->orWhere('parent_product_id', $id)
                ->update(['checkout_discount_percentage' => 5.00, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        $id = DB::table('products')->where('slug', self::SLUG)->value('id');
        if ($id) {
            DB::table('products')->where('id', $id)->orWhere('parent_product_id', $id)
                ->update(['checkout_discount_percentage' => 2.00, 'updated_at' => now()]);
        }
    }
};
