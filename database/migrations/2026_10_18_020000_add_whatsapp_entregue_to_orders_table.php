<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp "Seu pedido foi entregue" (2026-10-17): trava de um aviso por
 * pedido e a marca de quando o cliente tocou em "Não Recebi".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('whatsapp_entregue_em')->nullable();
            $table->timestamp('whatsapp_nao_recebi_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_entregue_em', 'whatsapp_nao_recebi_em']);
        });
    }
};
