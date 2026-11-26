<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Palavras extras que a busca do site, do admin e da API também procuram
 * (pedido do Lira 25/11/2026: títulos persuasivos). O título novo pode não
 * ter "lixeira" ou "pistola de água"; o nome antigo fica aqui e a busca
 * continua achando o produto. Preenchido pelo produtos:titulos-persuasivos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'termos_busca')) {
            return;
        }
        Schema::table('products', function (Blueprint $table) {
            $table->text('termos_busca')->nullable()->after('tags');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'termos_busca')) {
            Schema::table('products', fn (Blueprint $table) => $table->dropColumn('termos_busca'));
        }
    }
};
