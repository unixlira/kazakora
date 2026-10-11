<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suporte da loja (pedido 2026-10-11): chat "Falar com suporte" na compra.
 * A Manuela responde primeiro e abre o chamado quando o caso é sério; no
 * admin (Suporte > Chamados) o atendente liga "Atendimento humano" e
 * responde. Toda mensagem fica registrada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suporte_chamados', function (Blueprint $table) {
            $table->id();
            $table->string('numero', 20)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('assunto', 160)->nullable();
            // conversa (Manuela atendendo) | aberto | em_atendimento | resolvido | fechado
            $table->string('status', 20)->default('conversa')->index();
            // baixa | normal | alta | urgente
            $table->string('prioridade', 10)->default('normal');
            $table->boolean('humano_ativo')->default(false);
            $table->string('aberto_por', 20)->nullable();
            $table->text('resumo')->nullable();
            $table->unsignedInteger('nao_lidas_admin')->default(0);
            $table->timestamp('ultima_mensagem_em')->nullable()->index();
            $table->timestamp('aberto_em')->nullable();
            $table->timestamp('fechado_em')->nullable();
            $table->timestamps();
        });

        Schema::create('suporte_mensagens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chamado_id')->constrained('suporte_chamados')->cascadeOnDelete();
            // cliente | manuela | atendente | sistema
            $table->string('autor', 20);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('conteudo');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suporte_mensagens');
        Schema::dropIfExists('suporte_chamados');
    }
};
