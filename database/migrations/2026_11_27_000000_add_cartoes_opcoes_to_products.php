<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opções do anúncio em cartões, no formato dos kits do Livro Falante (pedido do Lira
 * 26/11/2026, Compressor 4 em 1). JSON com título e itens [{id, nome, itens, vantagens,
 * destaque, selo}]; formato em App\Modules\Catalog\Support\CartoesDeOpcoes. Vazio = o
 * seletor de variação de sempre.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'cartoes_opcoes')) {
            return;
        }
        Schema::table('products', function (Blueprint $table) {
            $table->json('cartoes_opcoes')->nullable()->after('escada_kits');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'cartoes_opcoes')) {
            Schema::table('products', fn (Blueprint $table) => $table->dropColumn('cartoes_opcoes'));
        }
    }
};
