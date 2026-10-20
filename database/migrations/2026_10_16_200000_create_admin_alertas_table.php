<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Alertas do painel do admin (2026-10-16): problema que precisa de ação
     * do Lira fica numa faixa vermelha no topo de todas as telas do admin até
     * ser resolvido. O mesmo problema (tipo + chave) aberto só soma ocorrência.
     */
    public function up(): void
    {
        Schema::create('admin_alertas', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 60);
            $table->string('chave', 120);
            $table->string('titulo', 200);
            $table->text('mensagem');
            $table->string('link', 255)->nullable();
            $table->unsignedInteger('ocorrencias')->default(1);
            $table->timestamp('primeira_em');
            $table->timestamp('ultima_em');
            $table->timestamp('resolvido_em')->nullable();
            $table->string('resolvido_por', 120)->nullable();
            $table->timestamps();

            $table->index(['tipo', 'chave', 'resolvido_em']);
            $table->index('resolvido_em');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_alertas');
    }
};
