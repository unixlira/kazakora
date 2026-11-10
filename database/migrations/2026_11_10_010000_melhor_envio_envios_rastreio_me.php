<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Código de rastreio do próprio Melhor Envio (ME262FTSE99BR), o que o
 * Melhor Rastreio aceita para qualquer transportadora (2026-11-10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('melhor_envio_envios', function (Blueprint $table) {
            $table->string('rastreio_me', 40)->nullable()->after('rastreio');
        });
    }

    public function down(): void
    {
        Schema::table('melhor_envio_envios', function (Blueprint $table) {
            $table->dropColumn('rastreio_me');
        });
    }
};
