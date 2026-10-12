<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Localização aproximada de quem fez o pedido no site (pedido do Lira,
 * 2026-10-12): latitude e longitude que a Cloudflare tira do IP
 * (cabeçalhos CF-IPLatitude/CF-IPLongitude, regra "Add visitor location
 * headers"). Nulo sem Cloudflare ou nos pedidos dos marketplaces.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('visitante_latitude', 10, 7)->nullable()->after('shipping_state');
            $table->decimal('visitante_longitude', 10, 7)->nullable()->after('visitante_latitude');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['visitante_latitude', 'visitante_longitude']);
        });
    }
};
