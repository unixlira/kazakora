<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SKU anterior do produto (pedido do usuário em 2026-10-14): na troca para o
 * padrão TIPO-ID-VAR o SKU antigo fica guardado aqui, porque Bling, Amazon e
 * TikTok casam pedido e estoque pelo SKU que já conheciam.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('sku_anterior')->nullable()->after('sku')->index();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['sku_anterior']);
            $table->dropColumn('sku_anterior');
        });
    }
};
