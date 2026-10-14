<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido 2026-10-14: o card do Flex no KoraSync mostra o dia que o Mercado
 * Livre prometeu a entrega ao comprador ("entregar hoje", "entregar
 * amanhã"...). O dado vem de `shipping_option.estimated_delivery_limit` do
 * envio e não era guardado em lugar nenhum.
 *
 * Só o dia (o ML manda meia-noite de São Paulo). Null = canal sem promessa
 * conhecida (Shopee, TikTok, Amazon) ou envio ainda não lido.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_shipments', function (Blueprint $table) {
            $table->date('promised_delivery_on')->nullable()->after('scheduled_for');
        });
    }

    public function down(): void
    {
        Schema::table('channel_shipments', function (Blueprint $table) {
            $table->dropColumn('promised_delivery_on');
        });
    }
};
