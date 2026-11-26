<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visita que não conta (pedido do Lira em 26/11/2026: "limpa isso"): robôs
 * e o próprio dono/equipe entravam na contagem. A linha fica guardada, só
 * marcada com o motivo (VisitaIgnorada::MOTIVOS); nulo = visita que conta.
 * O model SiteVisit esconde as marcadas em toda consulta (escopo global).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_visits', function (Blueprint $table) {
            $table->string('ignorada', 20)->nullable()->after('consentiu')->index();
        });
    }

    public function down(): void
    {
        Schema::table('site_visits', function (Blueprint $table) {
            $table->dropIndex(['ignorada']);
            $table->dropColumn('ignorada');
        });
    }
};
