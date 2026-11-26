<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * checkout_eventos.evento tinha 20 caracteres e o evento
 * "whatsapp_alerta_ddd55" (24/11/2026) tem 21: no MySQL da produção o lote
 * inteiro do checkout falhava com "Data too long for column 'evento'"
 * (10 erros em 24/11 e 10 em 25/11), perdendo também os outros eventos do
 * mesmo lote. Passa para 40, igual ao limite de nome dos outros eventos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_eventos', function (Blueprint $table) {
            $table->string('evento', 40)->change();
        });
    }

    public function down(): void
    {
        Schema::table('checkout_eventos', function (Blueprint $table) {
            $table->string('evento', 20)->change();
        });
    }
};
