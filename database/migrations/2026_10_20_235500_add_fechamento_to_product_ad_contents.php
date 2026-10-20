<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Frase de fechamento da descrição (pedido 2026-10-20, Lanterna LED): fica no
 * lugar do COMPRAR do fim, na caixa do "Veja na prática". Escrita à mão; o
 * Gemini não gera nem sobrescreve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_ad_contents', function (Blueprint $table) {
            $table->string('fechamento')->nullable()->after('chamada');
        });
    }

    public function down(): void
    {
        Schema::table('product_ad_contents', function (Blueprint $table) {
            $table->dropColumn('fechamento');
        });
    }
};
