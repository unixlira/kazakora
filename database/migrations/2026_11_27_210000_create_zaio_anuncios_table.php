<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Planilha da Zaio (agência de Shopee, ML e TikTok; pedido do Lira em
 * 27/11/2026): a conta de cada anúncio como a Zaio faz (preço, custo,
 * tarifa, taxa fixa, imposto, ADS/afiliados e comissão já embutidos no
 * preço), copiada do Google Sheets para o painel financeiro comparar com o
 * resultado real. Não entra no lucro: é a referência da agência.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zaio_anuncios', function (Blueprint $table) {
            $table->id();
            $table->string('canal', 30)->index();
            $table->string('anuncio_id', 40)->index();
            $table->string('nome', 300);
            $table->unsignedBigInteger('product_id')->nullable()->index();
            $table->decimal('custo', 10, 2)->nullable();
            $table->decimal('preco', 10, 2)->nullable();
            $table->decimal('renda_estimada', 10, 2)->nullable();
            $table->decimal('margem', 10, 2)->nullable();
            $table->decimal('margem_pct', 6, 2)->nullable();
            $table->decimal('margem_desejada_pct', 6, 2)->nullable();
            $table->decimal('preco_sugerido', 10, 2)->nullable();
            $table->decimal('tarifa_pct', 6, 2)->nullable();
            $table->decimal('taxa_fixa', 10, 2)->nullable();
            $table->decimal('imposto_pct', 6, 2)->nullable();
            $table->decimal('ads_pct', 6, 2)->nullable();
            $table->decimal('comissao_pct', 6, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zaio_anuncios');
    }
};
