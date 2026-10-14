<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Checkout próprio do FerraKora (pedido 2026-10-14): o pedido continua
 * origin "loja" (nota, expedição, Mercado Pago e margem iguais aos do site
 * KazaKora) e site_origem diz de qual site veio. Nulo = kazakora.com.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('site_origem', 30)->nullable()->after('origin')->index();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['site_origem']);
            $table->dropColumn('site_origem');
        });
    }
};
