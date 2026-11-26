<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Produto fora da vitrine da kazakora.com (pedido do Lira 26/11/2026, macaco elétrico repetido):
 * continua ativo para os anúncios de marketplace (ML, Shopee...) e para os pedidos, mas sai da
 * home, busca, categoria, sitemap e feed do Google. vitrine_kz_redireciona_para = id do produto
 * para onde a página antiga responde 301 (nulo = 404).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'fora_da_vitrine_kz')) {
            return;
        }
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('fora_da_vitrine_kz')->default(false)->after('so_ferrakora');
            $table->unsignedBigInteger('vitrine_kz_redireciona_para')->nullable()->after('fora_da_vitrine_kz');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'fora_da_vitrine_kz')) {
            Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['fora_da_vitrine_kz', 'vitrine_kz_redireciona_para']));
        }
    }
};
