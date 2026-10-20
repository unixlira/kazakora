<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Equipes de agentes de IA (pedido do Lira 2026-10-20, validado com o
 * Jonathan): menu Equipes › <squad>. A squad é uma entidade para dar para ter
 * outras no futuro; a primeira é a Alpha Kora. Cada membro é um agente do
 * Claude Code (slug = nome do arquivo .claude/agents/<slug>.md) e as
 * instruções extras ativas dele saem pela API GET /api/equipe/{slug}/instrucoes,
 * que o agente lê no começo de toda tarefa. Nada é apagado de verdade:
 * membro desativado é status, instrução excluída é soft delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('squads', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 80);
            $table->string('slug', 80)->unique();
            $table->boolean('ativa')->default(true);
            $table->timestamps();
        });

        Schema::create('squad_membros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('squad_id')->constrained('squads')->cascadeOnDelete();
            $table->string('nome', 80);
            // Identificador do agente: o mesmo nome do arquivo .claude/agents/<slug>.md.
            $table->string('slug', 80)->unique();
            $table->string('funcao', 160);
            $table->string('area', 30);
            $table->text('o_que_faz')->nullable();
            // [{nome, descricao}]
            $table->json('skills')->nullable();
            // Vazio = responde para o Homer.
            $table->foreignId('responde_para_id')->nullable()->constrained('squad_membros')->nullOnDelete();
            // Só exibição e cadastro: não dá nem tira permissão de verdade.
            $table->json('precisa_ok')->nullable();
            $table->string('status', 20)->default('ativo');
            $table->string('cor', 9)->nullable();
            $table->string('foto_path')->nullable();
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->timestamps();

            $table->index(['squad_id', 'status']);
        });

        Schema::create('squad_instrucoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('membro_id')->constrained('squad_membros')->cascadeOnDelete();
            $table->text('texto');
            $table->boolean('ativa')->default(true);
            $table->foreignId('criada_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['membro_id', 'ativa']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('squad_instrucoes');
        Schema::dropIfExists('squad_membros');
        Schema::dropIfExists('squads');
    }
};
