<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Carrinhos abandonados (pedido do Lira 2026-11-23): quando a mensagem de recuperação saiu pelo painel.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_checkout', function (Blueprint $table) {
            $table->timestamp('whatsapp_enviado_em')->nullable()->after('convertido_em');
        });
    }

    public function down(): void
    {
        Schema::table('lead_checkout', function (Blueprint $table) {
            $table->dropColumn('whatsapp_enviado_em');
        });
    }
};
