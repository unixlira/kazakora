<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Texto do botão principal de compra da página do produto (pedido do Lira,
 * 2026-11-24): no lugar do "Comprar agora", uma chamada de cada produto
 * ("Quero meu Livro Falante"). Vazio = "Comprar agora". Preenchido junto com
 * o conteúdo do anúncio no cadastro e, para os antigos, por
 * `produtos:gerar-cta`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('cta_compra', 40)->nullable()->after('seo_description');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('cta_compra');
        });
    }
};
