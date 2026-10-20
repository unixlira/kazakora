<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Equipe › comandos extras (pedido do Lira 2026-10-20): instruções que o
 * Lira acrescenta pela tela para turbinar a skill de cada agente. Não se
 * apaga: desativar só tira do agente (ativo = false).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipe_comandos', function (Blueprint $table) {
            $table->id();
            $table->string('membro', 30);
            $table->text('texto');
            $table->boolean('ativo')->default(true);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['membro', 'ativo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipe_comandos');
    }
};
