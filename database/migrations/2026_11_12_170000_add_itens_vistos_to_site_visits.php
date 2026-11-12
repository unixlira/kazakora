<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Itens que estavam no checkout quando o visitante abriu a tela de
 * pagamento (pedido do Lira, 2026-11-12: no olho das Visitas, o valor e o
 * que foi colocado no checkout). Lista de {nome, quantidade, valor}. A
 * visita da tela de pagamento também passa a ter preco_visto (total) e
 * frete_visto. Nulo nas outras páginas e nas visitas anteriores.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_visits', function (Blueprint $table) {
            $table->json('itens_vistos')->nullable()->after('frete_visto');
        });
    }

    public function down(): void
    {
        Schema::table('site_visits', function (Blueprint $table) {
            $table->dropColumn('itens_vistos');
        });
    }
};
