<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Caixa ou envelope — decide o formato da pré-postagem automática dos
 * Correios (pedido explícito 2026-09-25, ver AmazonCorreiosShipping).
 * Envelope só precisa de peso; caixa precisa de peso e medidas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_fiscal_data', function (Blueprint $table) {
            $table->string('formato_embalagem', 10)->default('caixa')->after('profundidade_cm');
        });
    }

    public function down(): void
    {
        Schema::table('product_fiscal_data', function (Blueprint $table) {
            $table->dropColumn('formato_embalagem');
        });
    }
};
