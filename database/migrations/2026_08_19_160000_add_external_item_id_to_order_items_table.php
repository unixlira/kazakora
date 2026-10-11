<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUG REAL descoberto 2026-08-19 (produtos "embalando errado" no
 * KoraSync): quando um item de pedido do ML/Shopee chega sem produto local
 * mapeado, `OrderImportService::importNormalized()` tenta
 * `driver->autoImportProduct()` — se isso falhar (API do canal fora do ar
 * naquele instante, item pausado, etc.), o item fica com `product_id` nulo
 * **pra sempre**: o `external_id`/`external_model_id` do canal só existia
 * na variável em memória durante aquele import, nunca foi persistido em
 * lugar nenhum. Sem produto local, o KoraSync não tem SKU nenhum pra
 * mostrar ao embalador — achado real: 38 itens de pedidos reais (ML +
 * Shopee) acumulados sem produto vinculado, todo recuperado às cegas via
 * grep no log de erro (`storage/logs/laravel.log`), que só por sorte ainda
 * não tinha rotacionado.
 *
 * Essas 2 colunas fecham essa lacuna — persistidas SEMPRE (matched ou não,
 * ver OrderImportService), então dá pra tentar de novo depois (ver comando
 * `marketplace:relink-unmapped-items`) sem depender de log nenhum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('external_item_id')->nullable()->after('product_id');
            $table->string('external_model_id')->nullable()->after('external_item_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['external_item_id', 'external_model_id']);
        });
    }
};
