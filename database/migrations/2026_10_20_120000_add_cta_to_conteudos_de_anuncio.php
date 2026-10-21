<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CTA do conteúdo do anúncio (pedido 2026-10-20): chamada para a compra
 * gerada pelo Gemini que aparece logo depois das dúvidas comuns da página do
 * produto, na KazaKora e na Beauty Kora. Ver AnuncioConteudoService.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['product_ad_contents', 'beautykora_produto_conteudos'] as $tabela) {
            Schema::table($tabela, function (Blueprint $table) {
                $table->json('cta')->nullable()->after('duvidas');
            });
        }
    }

    public function down(): void
    {
        foreach (['product_ad_contents', 'beautykora_produto_conteudos'] as $tabela) {
            Schema::table($tabela, function (Blueprint $table) {
                $table->dropColumn('cta');
            });
        }
    }
};
