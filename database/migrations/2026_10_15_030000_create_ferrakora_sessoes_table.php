<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FerraKora (pedido do Lira 2026-10-14): login com a conta da KazaKora
 * (tabela users, banco compartilhado) e "Meus pedidos" só para quem entrou.
 * Cada login vira uma sessão aqui; o site guarda só o token, o banco guarda
 * o hash dele. A tabela ferrakora_clientes (conta separada) deixa de ser usada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ferrakora_sessoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expira_em');
            $table->timestamp('ultimo_uso_em')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ferrakora_sessoes');
    }
};
