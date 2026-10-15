<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Projeto de onde veio o pedido (pedido do Lira 2026-10-14), em número para
 * as métricas separadas de cada projeto: 1 KazaKora, 2 Beauty Kora,
 * 3 FerraKora. Marketplaces (Shopee, ML, TikTok, Amazon) e notas avulsas
 * são da KazaKora (1). O Order preenche sozinho a partir de site_origem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedTinyInteger('projeto_origem')->default(1)->after('site_origem')->index();
        });

        DB::table('orders')->where('site_origem', 'beautykora')->update(['projeto_origem' => 2]);
        DB::table('orders')->where('site_origem', 'ferrakora')->update(['projeto_origem' => 3]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['projeto_origem']);
            $table->dropColumn('projeto_origem');
        });
    }
};
