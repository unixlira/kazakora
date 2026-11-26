<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico do cliente (pedido do Lira 26/11/2026: "quero rastreio de
 * histórico total dos clientes"). O que o sistema não guardava até hoje e
 * passa a guardar a partir desta data, nas duas lojas: cadastro (com a
 * origem), login, saída, pedido de link e troca de senha, foto, endereço e
 * dados do perfil. Nada do passado é inventado: a tela mostra "registrado
 * desde" para cada tipo.
 *
 * site_visits.ferrakora_cliente_id: página da FerraKora aberta com a conta
 * logada E com o "Aceito" do aviso de cookies de lá (o site manda o token
 * da sessão junto com o aviso da visita só nesse caso).
 *
 * Guarda (privacidade:limpar): login, saída e ligação de visitas somem aos
 * 6 meses; IP e aparelho dos outros eventos também. O resto some com a conta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cliente_eventos', function (Blueprint $table) {
            $table->id();
            $table->string('site', 20);
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('ferrakora_cliente_id')->nullable()->constrained('ferrakora_clientes')->cascadeOnDelete();
            $table->string('tipo', 40);
            $table->json('detalhe')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('dispositivo', 10)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at']);
            $table->index(['ferrakora_cliente_id', 'created_at']);
            $table->index(['tipo', 'created_at']);
        });

        Schema::table('site_visits', function (Blueprint $table) {
            $table->unsignedBigInteger('ferrakora_cliente_id')->nullable()->after('user_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('site_visits', function (Blueprint $table) {
            $table->dropIndex(['ferrakora_cliente_id']);
            $table->dropColumn('ferrakora_cliente_id');
        });

        Schema::dropIfExists('cliente_eventos');
    }
};
