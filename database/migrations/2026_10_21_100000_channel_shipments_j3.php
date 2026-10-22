<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Envio Flex com etiqueta da J3 (ver J3FlexLabel): guarda o rastreio e o
 * código do pedido na J3 pra reimprimir sem criar outro pedido lá.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_shipments', function (Blueprint $table) {
            $table->string('j3_tracking_number')->nullable()->index()->after('tracking_code');
            $table->string('j3_order_code')->nullable()->after('j3_tracking_number');
        });
    }

    public function down(): void
    {
        Schema::table('channel_shipments', function (Blueprint $table) {
            $table->dropIndex(['j3_tracking_number']);
            $table->dropColumn(['j3_tracking_number', 'j3_order_code']);
        });
    }
};
