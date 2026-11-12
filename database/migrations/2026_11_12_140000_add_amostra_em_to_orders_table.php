<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido de amostra (TikTok "Amostra grátis" para criador): o produto sai,
 * mas não é venda. Pedido do Lira em 12/11/2026 (#4108): amostra não entra
 * no faturamento nem nas vendas do KoraSync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('amostra_em')->nullable()->after('estorno_origem');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('amostra_em');
        });
    }
};
