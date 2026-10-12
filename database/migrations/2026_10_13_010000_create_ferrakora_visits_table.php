<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Visitas FerraKora (pedido do Lira 2026-10-12): o site FerraKora
 * (ferrakora.devlira.com.br, na Hostinger) avisa cada página aberta em
 * POST /api/ferrakora/visita e a tela Sistema › Visitas FerraKora lista.
 * Tabela separada de site_visits para não misturar com a contagem do
 * painel da loja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ferrakora_visits', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 45)->nullable();
            $table->string('path', 250);
            $table->string('user_agent', 250)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
            $table->index(['ip', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ferrakora_visits');
    }
};
