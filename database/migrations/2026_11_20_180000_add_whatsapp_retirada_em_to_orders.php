<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Aviso "pedido aguardando retirada na agência" (2026-11-20): trava para não repetir o WhatsApp.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('whatsapp_retirada_em')->nullable()->after('whatsapp_entregue_em');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('whatsapp_retirada_em');
        });
    }
};
