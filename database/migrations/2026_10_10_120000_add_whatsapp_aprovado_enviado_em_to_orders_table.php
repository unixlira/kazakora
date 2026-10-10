<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca do WhatsApp de pedido aprovado (pedido 2026-10-10): o webhook do
 * pagamento pode chegar mais de uma vez; a mensagem só sai uma.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('whatsapp_aprovado_enviado_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('whatsapp_aprovado_enviado_em');
        });
    }
};
