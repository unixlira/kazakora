<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FerraKora (pedido do Lira 2026-10-14): menu de conta no topo do site, com
 * login e cadastro pela API /api/ferrakora/*, no mesmo modelo do Beauty Kora.
 * Tabela própria: cliente do FerraKora não vira usuário da loja KazaKora.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ferrakora_clientes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 191)->unique();
            $table->string('password');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ferrakora_clientes');
    }
};
