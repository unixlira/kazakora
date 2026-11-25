<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conteúdo da página de brinquedo por produto (pedido do Lira 25/11/2026: todos os
 * brinquedos com a página completa no formato do Livro Falante). JSON com título,
 * chips, seções, ícones, kits, passos, garantia e perguntas; o formato está em
 * App\Modules\Catalog\Support\PaginaBrinquedo. Vazio = a página usa o conteúdo do
 * anúncio (product_ad_contents) e o que faltar some.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'pagina_brinquedo')) {
            return;
        }
        Schema::table('products', function (Blueprint $table) {
            $table->json('pagina_brinquedo')->nullable()->after('avaliacoes_de');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'pagina_brinquedo')) {
            Schema::table('products', fn (Blueprint $table) => $table->dropColumn('pagina_brinquedo'));
        }
    }
};
