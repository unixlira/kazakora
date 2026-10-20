<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rastreio do site de 5 em 5 minutos (2026-10-18): trava de um aviso por
 * pedido para as etapas "saiu para entrega" e "aguardando retirada".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('whatsapp_saiu_entrega_em')->nullable();
            $table->timestamp('whatsapp_retirada_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_saiu_entrega_em', 'whatsapp_retirada_em']);
        });
    }
};
