<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Layout da página do produto (pedido do Lira 24/11/2026, página de brinquedo
 * no estilo de loja infantil): null = segue a categoria (brinquedos usam o
 * layout "brinquedo", o resto o "padrao"); "brinquedo" ou "padrao" força um
 * dos dois neste produto. Ver App\Modules\Catalog\Support\LayoutDaPagina.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'layout_pagina')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->string('layout_pagina', 20)->nullable()->after('variation');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('products', 'layout_pagina')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('layout_pagina');
        });
    }
};
