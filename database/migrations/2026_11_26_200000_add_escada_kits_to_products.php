<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Escada de kits entre produtos diferentes (pedido do Lira 26/11/2026, oferta do
 * Compressor 4 em 1 em 3 kits): cada kit é um produto próprio, com preço próprio,
 * e a página de cada um mostra os cartões de todos. JSON com título e itens
 * [{id, nome, itens, partes, acrescenta, destaque}]; formato em
 * App\Modules\Catalog\Support\EscadaDeKits. Vazio = sem escada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'escada_kits')) {
            return;
        }
        Schema::table('products', function (Blueprint $table) {
            $table->json('escada_kits')->nullable()->after('pagina_brinquedo');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'escada_kits')) {
            Schema::table('products', fn (Blueprint $table) => $table->dropColumn('escada_kits'));
        }
    }
};
