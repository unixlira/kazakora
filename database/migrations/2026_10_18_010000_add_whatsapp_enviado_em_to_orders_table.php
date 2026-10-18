<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trava do WhatsApp "Seu pedido saiu para entrega" (2026-10-17): uma vez
 * só por pedido, mesmo com a rotina de rastreio rodando de novo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('whatsapp_enviado_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('whatsapp_enviado_em');
        });
    }
};
