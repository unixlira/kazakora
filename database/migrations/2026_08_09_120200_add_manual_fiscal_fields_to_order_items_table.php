<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido explícito 2026-08-09: emissão manual de nota fiscal precisa
 * aceitar tanto produto do catálogo (já tem ProductFiscalData) quanto item
 * digitado na hora — serviço avulso ou produto fora do catálogo, já que a
 * empresa tem 2 CNAEs. Espelha as colunas de product_fiscal_data que
 * NFeXmlBuilderService realmente lê (não o conjunto inteiro — ver
 * ProductFiscalData, tem campo que o builder não usa hoje, ex.: peso/IPI).
 * Tudo nullable: só é usado quando o item NÃO tem product_id (NFeXmlBuilder
 * Service prioriza product->fiscalData quando existe, cai pra estas colunas
 * senão).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('item_type')->default('produto')->after('product_id'); // produto | servico
            $table->string('ncm', 10)->nullable()->after('item_type');
            $table->string('cest', 10)->nullable()->after('ncm');
            $table->string('cfop', 10)->nullable()->after('cest');
            $table->string('cfop_outros_estados', 10)->nullable()->after('cfop');
            $table->unsignedTinyInteger('origem_mercadoria')->nullable()->after('cfop_outros_estados'); // ICMS orig (0-8)
            $table->string('gtin', 20)->nullable()->after('origem_mercadoria');
            $table->string('unidade_tributavel', 6)->nullable()->after('gtin');
            $table->string('icms_situacao_tributaria', 10)->nullable()->after('unidade_tributavel'); // CSOSN
            $table->string('pis_situacao_tributaria', 4)->nullable()->after('icms_situacao_tributaria');
            $table->decimal('pis_aliquota', 5, 2)->nullable()->after('pis_situacao_tributaria');
            $table->string('cofins_situacao_tributaria', 4)->nullable()->after('pis_aliquota');
            $table->decimal('cofins_aliquota', 5, 2)->nullable()->after('cofins_situacao_tributaria');
            $table->decimal('percentual_aproximado_tributos', 5, 2)->nullable()->after('cofins_aliquota');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn([
                'item_type', 'ncm', 'cest', 'cfop', 'cfop_outros_estados', 'origem_mercadoria',
                'gtin', 'unidade_tributavel', 'icms_situacao_tributaria',
                'pis_situacao_tributaria', 'pis_aliquota', 'cofins_situacao_tributaria', 'cofins_aliquota',
                'percentual_aproximado_tributos',
            ]);
        });
    }
};
