<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cookies do visitante em cada visita (pedido 2026-10-12): JSON nome => valor,
 * só os da lista permitida (App\Support\Privacidade\Cookies) e só com o OK do
 * cliente. Visitas anteriores ficam nulas. Apagado junto com o IP (6 meses).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_visits', function (Blueprint $table) {
            $table->json('cookies')->nullable()->after('consentiu');
        });
    }

    public function down(): void
    {
        Schema::table('site_visits', function (Blueprint $table) {
            $table->dropColumn('cookies');
        });
    }
};
