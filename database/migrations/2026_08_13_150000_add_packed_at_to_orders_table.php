<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido explícito 2026-08-13: botão "Em preparação" -> "Embalado" no
 * KoraSync (app nativo) precisa de um jeito de marcar que um pedido pago já
 * foi separado/embalado, sem mexer em orders.status — esse campo continua
 * sendo a visão do MARKETPLACE sobre o pedido (pending/paid/shipped/...),
 * atualizada só via webhook (ver OrderImportService::syncStatus() e a trava
 * de regressão isStaleStatus()); embalagem é um passo interno nosso, que o
 * canal nem sabe que existe. Nullable: null = ainda não embalado (é o que
 * mantém o pedido na fila de expedição, ver DashboardAgentController::
 * queue()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('packed_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('packed_at');
        });
    }
};
