<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cliques nos selos de segurança do rodapé (pedido do Lira, 2026-10-25):
 * Site Browsing (Google Safe Browsing), Reclame Aqui e Norton Safe Web.
 * O clique soma na última visita do visitante; as visitas antigas ficam em 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_visits', function (Blueprint $table) {
            $table->unsignedInteger('cliques_selo_google')->default(0);
            $table->unsignedInteger('cliques_selo_reclame_aqui')->default(0);
            $table->unsignedInteger('cliques_selo_norton')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('site_visits', function (Blueprint $table) {
            $table->dropColumn(['cliques_selo_google', 'cliques_selo_reclame_aqui', 'cliques_selo_norton']);
        });
    }
};
