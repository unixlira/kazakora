<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Achado real 2026-08-15 (Ring Light 8" vs 10"): a Shopee (e outros canais
 * com variação) usa o MESMO item_id/external_id pro anúncio inteiro —
 * cada variação (model) tem seu próprio model_id/model_sku. Sem essa
 * coluna, product_channel_listings só conseguia vincular UM produto local
 * por (product_id, channel), e OrderImportService casava o item do pedido
 * só por external_id — toda venda do anúncio, de qualquer variação, caía
 * sempre no mesmo produto local (o que por acaso tinha o listing
 * cadastrado), estoque e SKU errados pra qualquer variação diferente
 * daquela. Nullable: produto sem variação continua com external_model_id
 * null, nenhuma migração de dado precisa tocar o que já funcionava.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_channel_listings', function (Blueprint $table) {
            $table->string('external_model_id')->nullable()->after('external_id');
        });
    }

    public function down(): void
    {
        Schema::table('product_channel_listings', function (Blueprint $table) {
            $table->dropColumn('external_model_id');
        });
    }
};
