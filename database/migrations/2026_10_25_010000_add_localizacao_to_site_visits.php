<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Latitude, longitude e CEP aproximados do IP de cada visita (pedido do
 * Lira, 2026-10-24: card de localização no olho das Visitas). Vêm dos
 * cabeçalhos da Cloudflare (LocalizacaoDaVisita). Nulo sem Cloudflare e
 * nas visitas anteriores; apagados com o IP depois de 6 meses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_visits', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('ip');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->string('cep', 8)->nullable()->after('longitude');
        });
    }

    public function down(): void
    {
        Schema::table('site_visits', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'cep']);
        });
    }
};
