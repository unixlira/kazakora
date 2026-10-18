<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resposta do cliente à confirmação de dados no WhatsApp (2026-10-17):
 * 'ok' (Tudo Certo) ou 'corrigir' (Corrigir Informações).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('whatsapp_confirmacao', 20)->nullable()->after('whatsapp_aprovado_enviado_em');
            $table->timestamp('whatsapp_confirmacao_em')->nullable()->after('whatsapp_confirmacao');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_confirmacao', 'whatsapp_confirmacao_em']);
        });
    }
};
